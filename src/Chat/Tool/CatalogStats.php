<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\SongTools;

/** One class per chat tool (see config/packages/ai.yaml); it calls the MCP tool, so chat and MCP share the code. */
final readonly class CatalogStats
{
    public function __construct(private SongTools $tools)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return $this->tools->stats();
    }
}
