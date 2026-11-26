<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\PackageTools;

final readonly class SearchPackagist
{
    public function __construct(private PackageTools $tools)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(string $query, ?string $type = null, int $limit = 10): array
    {
        return $this->tools->searchPackagist($query, $type, $limit);
    }
}
