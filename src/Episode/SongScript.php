<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * What the writer returns: the talk, segment by segment. Each excerpt plays between
 * a segment's intro and its background, so its place is the structure itself.
 */
final readonly class SongScript
{
    /**
     * @param list<Line>        $opening
     * @param list<SongSegment> $segments
     * @param list<Line>        $closing
     */
    public function __construct(
        public string $title,
        #[Schema(description: 'Two to four lines before the first song: a hook and the theme')]
        public array $opening,
        #[Schema(description: 'One segment per song, in the order of the user message')]
        public array $segments,
        #[Schema(description: 'One to three lines after the last song: a short sign-off')]
        public array $closing,
        #[Schema(description: 'Two sentences for the show notes: the theme and the songs, only facts from the sources')]
        public string $summary = '',
    ) {
    }

    /** @return list<Line> every line, in the order they are spoken */
    public function lines(): array
    {
        $lines = $this->opening;
        foreach ($this->segments as $segment) {
            $lines = [...$lines, ...$segment->intro, ...$segment->background];
        }

        return [...$lines, ...$this->closing];
    }
}
