<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final readonly class SongSegment
{
    /**
     * @param list<Line> $intro
     * @param list<Line> $background
     */
    public function __construct(
        #[Schema(description: 'Number of the song, as numbered in the user message')]
        public int $song,
        #[Schema(description: 'Two to four lines before the excerpt: the hosts introduce the song, the last line hands over to it')]
        public array $intro,
        #[Schema(description: 'Three to five lines after the excerpt: the hosts react to it and give the background of the song from its source, the last line leads to the next song or to the closing')]
        public array $background,
    ) {
    }
}
