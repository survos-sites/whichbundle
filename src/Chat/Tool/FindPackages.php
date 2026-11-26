<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\PackageTools;

/** One class per chat tool (see config/packages/ai.yaml); it calls the MCP tool, so chat and MCP share the code. */
final readonly class FindPackages
{
    public function __construct(private PackageTools $tools)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $question, int $limit = 10, bool $bundlesOnly = false, bool $includeRejected = false, ?string $symfony = null): array
    {
        return $this->tools->find($question, $limit, $bundlesOnly, $includeRejected, $symfony);
    }
}
