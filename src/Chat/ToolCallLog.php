<?php

declare(strict_types=1);

namespace App\Chat;

use Symfony\AI\Agent\Toolbox\Event\{ToolCallFailed, ToolCallSucceeded};
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Records the tool calls of one chat turn, so the page can show what the agent looked up. */
final class ToolCallLog
{
    /** @var list<array{name:string, arguments:array<string, mixed>, results:?int, failed:bool}> */
    private array $calls = [];

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return list<array{name:string, arguments:array<string, mixed>, results:?int, failed:bool}> */
    public function calls(): array
    {
        return $this->calls;
    }

    #[AsEventListener]
    public function succeeded(ToolCallSucceeded $event): void
    {
        $result = $event->getResult()->getResult();
        $this->calls[] = ['name' => $event->getDefinition()->getName(), 'arguments' => $event->getArguments(),
            'results' => self::count($result), 'failed' => false];
    }

    /** A list counts its items; find_packages returns {semantic, results: [...]}. */
    private static function count(mixed $result): ?int
    {
        return match (true) {
            is_array($result) && array_is_list($result) => count($result),
            is_array($result) && is_array($result['results'] ?? null) => count($result['results']),
            default => null,
        };
    }

    #[AsEventListener]
    public function failed(ToolCallFailed $event): void
    {
        $this->calls[] = ['name' => $event->getDefinition()->getName(), 'arguments' => $event->getArguments(), 'results' => null, 'failed' => true];
    }
}
