<?php

declare(strict_types=1);

namespace App\Episode;

/**
 * One song of a themed episode: what the hosts may say about it (its only source)
 * and the part of a recording played on air.
 */
final readonly class SongCard
{
    public function __construct(
        public string $songId,
        public string $title,
        public string $facts,
        public string $recording,
        public string $performance,
        public float $excerptStart,
        public float $excerptEnd,
        public string $excerptLyrics = '',
    ) {
        if ($excerptEnd <= $excerptStart) {
            throw new \InvalidArgumentException(sprintf('The excerpt of "%s" ends before it starts.', $title));
        }
    }

    public function excerptLength(): float
    {
        return $this->excerptEnd - $this->excerptStart;
    }

    /** e.g. "0:11.5 → 0:31.0" */
    public function excerptAt(): string
    {
        return sprintf('%s → %s', self::at($this->excerptStart), self::at($this->excerptEnd));
    }

    private static function at(float $seconds): string
    {
        return sprintf('%d:%04.1f', intdiv((int) $seconds, 60), fmod($seconds, 60));
    }
}
