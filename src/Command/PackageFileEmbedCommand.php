<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Package;
use App\Entity\PackageFile;
use App\Service\PackageFileEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class PackageFileEmbedCommand
{
    public function __construct(private EntityManagerInterface $em, private PackageFileEmbeddingService $service) {}

    #[AsCommand('app:package-files:embed', 'Ensure all stored files of one package and populate ready chunk vectors (no model calls).')]
    public function embed(SymfonyStyle $io, #[Argument] string $package, #[Option('Save an immutable reload fixture as .jsonl.gz')] ?string $freeze = null): int
    {
        if (!$this->service->embeddings) { throw new \LogicException('Enable survos_ai_embed first.'); }
        $p = $this->em->getRepository(Package::class)->findOneBy(['name' => $package]) ?? throw new \InvalidArgumentException('Unknown package.');
        $files = $this->em->getRepository(PackageFile::class)->findBy(['package' => $p], ['id' => 'ASC']);
        if (!$files) { throw new \LogicException('No stored files.'); }
        $totals = ['files' => count($files), 'chunks' => 0, 'pending' => 0, 'cacheHits' => 0];
        foreach ($files as $file) {
            $result = $this->service->ensure($file);
            foreach (['chunks', 'pending', 'cacheHits'] as $key) { $totals[$key] += $result[$key]; }
        }
        if ($freeze) {
            if (!str_ends_with($freeze, '.jsonl.gz') || file_exists($freeze)) { throw new \InvalidArgumentException('Use a new .jsonl.gz filename.'); }
            $db = $this->em->getConnection();
            $writer = JsonlWriter::open($freeze, 'w');
            try {
                $writer->write(['type' => 'fixture', 'schemaVersion' => 1, 'package' => $p->name,
                    'profileId' => $this->service->embeddings->profile->id(),
                    'legacy' => $db->fetchAssociative('SELECT embedding, embedding_fingerprint FROM package WHERE id = ?', [$p->id])]);
                foreach ($db->fetchAllAssociative('SELECT * FROM package_file WHERE package_id = ? ORDER BY id', [$p->id]) as $row) {
                    $writer->write(['type' => 'file', 'row' => $row]);
                }
                $writer->finish();
            } finally { $writer->close(); }
        }
        $io->writeln(json_encode($totals, JSON_THROW_ON_ERROR));
        return 0;
    }

    #[AsCommand('app:package-files:import-local', 'Import README, AGENTS and docs text files from a local repository; existing package vectors are preserved.')]
    public function importLocal(SymfonyStyle $io, #[Argument] string $package, #[Argument] string $directory): int
    {
        $p = $this->em->getRepository(Package::class)->findOneBy(['name' => $package]) ?? throw new \InvalidArgumentException('Unknown package.');
        $root = realpath($directory) ?: throw new \InvalidArgumentException('Repository directory missing.');
        $paths = [];
        foreach (['README.md', 'AGENTS.md'] as $path) { if (is_file($root.'/'.$path)) { $paths[] = $path; } }
        if (is_dir($root.'/docs')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/docs', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink() && in_array(strtolower($file->getExtension()), ['md', 'txt', 'rst', 'adoc'], true)) {
                    $paths[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($paths);
        $imported = 0;
        foreach ($paths as $path) {
            $content = file_get_contents($root.'/'.$path);
            if (trim($content) === '' || !mb_check_encoding($content, 'UTF-8')) { continue; }
            $kind = match ($path) { 'README.md' => PackageFile::README, 'AGENTS.md' => PackageFile::AGENTS, default => PackageFile::DOCUMENT };
            $new = new PackageFile($p, $kind, $path, $content);
            if ($this->em->find(PackageFile::class, $new->id)) { continue; }
            foreach ($this->em->getRepository(PackageFile::class)->findBy(['package' => $p, 'kind' => $kind]) as $old) {
                if ($kind !== PackageFile::DOCUMENT || $old->path === $path) { $this->em->remove($old); }
            }
            $this->em->persist($new);
            ++$imported;
        }
        $this->em->flush();
        $io->writeln(json_encode(['imported' => $imported, 'scanned' => count($paths)], JSON_THROW_ON_ERROR));
        return 0;
    }
}
