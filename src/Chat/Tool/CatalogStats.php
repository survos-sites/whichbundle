<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\PackageTools;

final readonly class CatalogStats
{
    public function __construct(private PackageTools $tools)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return $this->tools->stats();
    }
}
