<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Read-only experiment input. No ensure/consume/provider path is invoked. */
final readonly class PackageClusteringService
{
    public function __construct(private EntityManagerInterface $em, private PackageEmbeddingService $embeddings) {}

    #[AsCommand('app:packages:cluster-export', 'Freeze a deterministic sample of current cached package vectors and Composer keywords; no model calls.')]
    public function export(SymfonyStyle $io, #[Argument] string $path,
        #[Option('Number of packages, selected by seeded name hash rather than tags')] int $limit = 192,
        #[Option('Stable sampling seed')] string $seed = 'packages-clustering-v1'): int
    {
        if ($limit < 12 || $limit > 1000 || !str_ends_with($path, '.jsonl.gz') || file_exists($path)) {
            throw new \InvalidArgumentException('Choose 12–1000 packages and a new .jsonl.gz path.');
        }
        $sample = [];
        $scanned = $eligible = 0;
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            foreach ($this->em->createQuery("SELECT p FROM App\\Entity\\Package p WHERE p.state = 'documented' AND p.embedding IS NOT NULL ORDER BY p.name")->toIterable() as $package) {
                ++$scanned;
                if ($this->embeddings->isCurrent($package)) {
                    ++$eligible;
                    $rank = hash('sha256', $seed."\0".$package->name);
                    if (count($sample) < $limit || $rank < array_key_last($sample)) {
                        $sample[$rank] = ['type' => 'package', 'name' => $package->name, 'description' => $package->description,
                            'keywords' => array_values(array_filter($package->keywords, is_string(...))),
                            'fingerprint' => $package->embeddingFingerprint, 'vector' => $package->embedding,
                            'readmeCharacters' => mb_strlen($package->readme ?? '', 'UTF-8'),
                            'readmeHeadings' => preg_match_all('/^#{1,6}\s/m', $package->readme ?? ''),
                            'readmeCodeFences' => preg_match_all('/^\s*(```|~~~)/m', $package->readme ?? '')];
                        ksort($sample);
                        if (count($sample) > $limit) { array_pop($sample); }
                    }
                }
                $this->em->detach($package);
            }
            $db->commit();
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        if (count($sample) < 12) { throw new \LogicException('Not enough current cached embeddings; this command never generates replacements.'); }
        $metadata = ['type' => 'manifest', 'schemaVersion' => 1, 'source' => 'legacy-package-cache',
            'profile' => (array) $this->embeddings->profile, 'seed' => $seed, 'sampleSize' => count($sample),
            'scanned' => $scanned, 'eligible' => $eligible, 'excludedStaleOrInvalid' => $scanned - $eligible,
            'selection' => 'smallest SHA-256(seed + NUL + package name)', 'embeddingCalls' => 0];
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0770, true); }
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.jsonl.gz';
        $writer = JsonlWriter::open($temporary, 'w');
        try {
            $writer->write($metadata);
            foreach ($sample as $row) { $writer->write($row); }
            $writer->finish();
        } finally { $writer->close(); }
        if (file_exists($path) || !rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('Cannot install frozen fixture.'); }
        $io->writeln(json_encode($metadata + ['sha256' => hash_file('sha256', $path)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return 0;
    }
}
