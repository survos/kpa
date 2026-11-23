<?php

declare(strict_types=1);

namespace App\Chat\Tool;

use App\Mcp\SongTools;

/** One class per chat tool (see config/packages/ai.yaml); it calls the MCP tool, so chat and MCP share the code. */
final readonly class SearchLyrics
{
    public function __construct(private SongTools $tools)
    {
    }

    /** @return list<array<string, mixed>> */
    public function __invoke(string $phrase, int $limit = 10): array
    {
        return $this->tools->searchLyrics($phrase, $limit);
    }
}
