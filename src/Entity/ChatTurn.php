<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One question asked on /ask and what came back, kept to see what people look for and where the
 * catalog falls short. No IP address, user agent or account is stored; the conversation id is a
 * random value held in the visitor's session. The /ask page says these are saved, and
 * app:chat:purge deletes them after RETENTION_DAYS.
 */
#[ORM\Entity]
#[ORM\Table(name: 'chat_turn')]
#[ORM\Index(columns: ['created_at'])]
#[ORM\Index(columns: ['conversation'])]
class ChatTurn
{
    public const int RETENTION_DAYS = 90;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private(set) Uuid $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private(set) \DateTimeImmutable $createdAt;

    /**
     * @param list<array<string, mixed>> $tools the lookups the agent made (ToolCallLog::calls())
     */
    public function __construct(
        #[ORM\Column(type: 'uuid')]
        private(set) readonly Uuid $conversation,
        #[ORM\Column(type: Types::TEXT)]
        private(set) readonly string $question,
        #[ORM\Column(type: Types::TEXT)]
        private(set) readonly string $answer,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private(set) readonly array $tools,
        #[ORM\Column]
        private(set) readonly bool $failed,
        #[ORM\Column(length: 64, nullable: true)]
        private(set) readonly ?string $model,
        // Wall-clock time of the agent call, in milliseconds.
        #[ORM\Column]
        private(set) readonly int $durationMs,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }
}
