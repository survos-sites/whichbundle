<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\PackageService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recompute Package::$phpVersions from the stored require.php constraint after PackageService::PHP_VERSIONS
 * changes, without reloading from Packagist. Only phpVersions changes: rejection reasons and workflow places are
 * left alone (a package that becomes PHP-incompatible is reported, not moved). The elastic_outbox triggers queue
 * changed rows; run elastic:outbox:consume afterwards.
 */
#[AsCommand('app:recompute-php-versions', 'Recompute supported PHP versions from stored constraints')]
final readonly class RecomputePhpVersionsCommand
{
    public function __construct(private Connection $db, private PackageService $packages)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Report the changes without saving them')] bool $dryRun = false): int
    {
        $rows = $this->db->fetchAllAssociative('SELECT id, state, php_version_string, php_versions FROM package WHERE php_version_string IS NOT NULL');
        $changed = 0;
        $nowIncompatible = [];
        $this->db->beginTransaction();
        foreach ($io->progressIterate($rows) as $row) {
            $old = json_decode((string) $row['php_versions'], true) ?? [];
            $new = $this->packages->constraintComplies($row['php_version_string'], PackageService::PHP_VERSIONS);
            if ($old === $new) {
                continue;
            }
            ++$changed;
            if ($new === [] && 'rejected' !== $row['state']) {
                $nowIncompatible[] = $row['id'];
            }
            if (!$dryRun) {
                $this->db->update('package', ['php_versions' => json_encode($new)], ['id' => $row['id']]);
            }
        }
        $dryRun ? $this->db->rollBack() : $this->db->commit();

        $io->success(sprintf('%d of %d packages %s new PHP versions (%s).', $changed, count($rows), $dryRun ? 'would get' : 'got', implode(', ', PackageService::PHP_VERSIONS)));
        if ($nowIncompatible !== []) {
            $io->warning(sprintf('%d packages no longer support any listed PHP version but were not rejected: %s', count($nowIncompatible), implode(', ', array_slice($nowIncompatible, 0, 20))));
        }

        return Command::SUCCESS;
    }
}
