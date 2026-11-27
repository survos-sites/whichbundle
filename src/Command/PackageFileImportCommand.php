<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Package;
use App\Entity\PackageFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Imports a package's README, AGENTS and docs/ text files from a local checkout into PackageFile. */
final readonly class PackageFileImportCommand
{
    public function __construct(private EntityManagerInterface $em) {}

    #[AsCommand('app:package-files:import-local', 'Import README, AGENTS and docs text files from a local repository')]
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
