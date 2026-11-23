<?php

declare(strict_types=1);

namespace App\Episode;

/**
 * A themed episode ready for the writer: the songs in the order they are played,
 * and the sources not about one song (Kid Pan Alley itself).
 */
final readonly class SongBrief
{
    /**
     * @param list<SongCard>                             $songs
     * @param list<array{title: string, notes: string}> $sources
     * @param list<string>                               $hosts ids of the two hosts
     */
    public function __construct(
        public string $theme,
        public array $songs,
        public array $sources,
        public array $hosts,
        // minutes of talk, the excerpts come on top
        public int $minutes = 2,
        public ?string $tone = null,
    ) {
        if (\count($songs) < 2 || \count($songs) > 12) {
            throw new \InvalidArgumentException('A themed episode has 2 to 12 songs.');
        }
        if ($minutes < 1 || $minutes > 10) {
            throw new \InvalidArgumentException('A themed episode has 1 to 10 minutes of talk.');
        }
    }

    public function sourceCount(): int
    {
        return \count($this->songs) + \count($this->sources);
    }
}
