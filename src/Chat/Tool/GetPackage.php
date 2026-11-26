<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\PackageTools;

final readonly class GetPackage
{
    public function __construct(private PackageTools $tools)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $name): array
    {
        return $this->tools->get($name);
    }
}
