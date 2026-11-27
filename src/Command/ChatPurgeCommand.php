<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ChatTurn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Deletes saved /ask questions and answers older than the retention the page promises. Runs daily (PackagistSchedule). */
#[AsCommand('app:chat:purge', 'Delete saved /ask questions and answers older than the retention period')]
final readonly class ChatPurgeCommand
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option('Days to keep')] int $days = ChatTurn::RETENTION_DAYS): int
    {
        $deleted = $this->em->createQuery('DELETE FROM '.ChatTurn::class.' t WHERE t.createdAt < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable(sprintf('-%d days', max(1, $days))))
            ->execute();
        $io->success(sprintf('Deleted %d chat turn%s older than %d days.', $deleted, 1 === $deleted ? '' : 's', $days));

        return Command::SUCCESS;
    }
}
