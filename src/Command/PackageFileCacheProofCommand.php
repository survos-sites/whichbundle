<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Package;
use App\Entity\PackageFile;
use App\Service\PackageFileEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:package-files:prove-cached', 'Reload a frozen local PackageFile fixture and prove identical vectors with zero provider calls.')]
final readonly class PackageFileCacheProofCommand
{
    public function __construct(private EntityManagerInterface $em, private PackageFileEmbeddingService $service,
        #[Autowire('%kernel.environment%')] private string $environment) {}

    public function __invoke(SymfonyStyle $io, #[Argument] string $fixture): int
    {
        $db = $this->em->getConnection();
        if ($this->environment !== 'dev' || !in_array($db->getParams()['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \LogicException('This reload proof is limited to a local dev database.');
        }
        $embeddings = $this->service->embeddings ?? throw new \LogicException('Embeddings disabled.');
        $records = iterator_to_array(JsonlReader::open($fixture), false);
        $header = array_shift($records);
        if (($header['type'] ?? '') !== 'fixture' || ($header['schemaVersion'] ?? 0) !== 1 || $header['profileId'] !== $embeddings->profile->id()) {
            throw new \LogicException('Fixture/profile mismatch.');
        }
        $p = $this->em->getRepository(Package::class)->findOneBy(['name' => $header['package']]) ?? throw new \LogicException('Package missing.');
        $rows = array_map(static fn ($r) => $r['row'], $records);
        if (!$rows || count($rows) !== (int) $db->fetchOne('SELECT count(*) FROM package_file WHERE package_id = ?', [$p->id])) {
            throw new \LogicException('Fixture must cover exactly the current package files.');
        }
        $ids = [];
        foreach ($rows as $row) {
            if ($row['package_id'] !== $p->id || isset($ids[$row['id']])) { throw new \LogicException('Invalid fixture membership.'); }
            $ids[$row['id']] = true;
            $current = $db->fetchAssociative('SELECT * FROM package_file WHERE id = ?', [$row['id']]);
            foreach (['package_id', 'content', 'sha', 'kind', 'path'] as $key) {
                if (!$current || $current[$key] !== $row[$key]) { throw new \LogicException('Source changed since freezing.'); }
            }
        }
        $legacy = fn () => $db->fetchAssociative('SELECT embedding, embedding_fingerprint FROM package WHERE id = ?', [$p->id]);
        if ($legacy() !== $header['legacy']) { throw new \LogicException('Legacy vector changed since freezing.'); }
        $project = function () use ($p): array {
            $totals = ['chunks' => 0, 'pending' => 0, 'cacheHits' => 0];
            foreach ($this->em->getRepository(PackageFile::class)->findBy(['package' => $p]) as $file) {
                $result = $this->service->ensure($file);
                foreach ($totals as $key => $_) { $totals[$key] += $result[$key]; }
            }
            if ($totals['pending'] || !$totals['chunks']) { throw new \LogicException('Generate every fixture embedding before attempting the reload proof.'); }
            return $totals;
        };
        $snapshot = fn () => $db->fetchAllAssociative('SELECT e.* FROM package_file_embedding e JOIN package_file f ON f.id=e.file_id WHERE f.package_id=? ORDER BY e.id', [$p->id]);
        $callsBefore = $embeddings->cache->callCount();
        $project();
        $before = $snapshot();
        // Keep every model projection, including profiles other than the currently selected one.
        $other = array_filter($before, fn ($r) => $r['profile_id'] !== $embeddings->profile->id());
        $db->beginTransaction();
        try {
            $db->delete('package_file', ['package_id' => $p->id]);
            foreach ($rows as $row) { $db->insert('package_file', $row); }
            foreach ($other as $row) { $db->insert('package_file_embedding', $row); }
            $this->em->clear();
            $totals = $project();
            // Exercise the real queue consumer only after ensuring no misses anywhere in this profile.
            $pending = $embeddings->cache->execute("SELECT count(*) FROM requests WHERE profile_id=? AND status <> 'ready'", [$embeddings->profile->id()])->fetchColumn();
            if ($pending) { throw new \LogicException('Unrelated pending cache work: drain it before the proof.'); }
            $drain = $embeddings->consume();
            $after = $snapshot();
            if ($before !== $after || $legacy() !== $header['legacy'] || $drain['modelCalls'] !== 0 || $embeddings->cache->callCount() !== $callsBefore) {
                throw new \LogicException('Reload proof failed; app transaction rolled back.');
            }
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        $proof = ['package' => $header['package'], 'fixtureSha256' => hash_file('sha256', $fixture),
            'profileId' => $header['profileId'], 'filesReloaded' => count($rows), ...$totals,
            'providerCallsBefore' => $callsBefore, 'providerCallsAfter' => $embeddings->cache->callCount(),
            'consumer' => $drain, 'vectorsIdentical' => true, 'legacyUnchanged' => true,
            'projectionSha256' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR))];
        $io->writeln(json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return 0;
    }
}
