<?php

declare(strict_types=1);

namespace App\Episode;

use App\Entity\Song;
use App\Service\SongEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the songs that fit a theme, by meaning (the songs' persisted embeddings), among
 * those that have lyrics and a recording that is not AI-generated.
 */
final readonly class ThemeFinder
{
    public function __construct(
        private EntityManagerInterface $em,
        private SongEmbeddingService $embeddings,
    ) {
    }

    /**
     * @return list<array{song: Song, score: float}> best first
     */
    public function find(string $theme, int $limit = 12): array
    {
        if (!$this->embeddings->enabled) {
            throw new \RuntimeException('AI is paused in this app (KPA_AI_ENABLED=0): themes are found by meaning.');
        }
        $query = $this->embeddings->embed($theme);

        $songs = $this->em->createQuery(
            'SELECT s FROM App\Entity\Song s
             WHERE s.lyrics IS NOT NULL AND s.embedding IS NOT NULL AND EXISTS (
                 SELECT a.id FROM App\Entity\Audio a JOIN a.fileAsset f
                 WHERE a.song = s AND LOWER(f.path) NOT LIKE :suno AND LOWER(f.path) NOT LIKE :ai
             )'
        )->setParameters(['suno' => '%suno%', 'ai' => '%ai recordings%'])->getResult();

        $scored = [];
        foreach ($songs as $song) {
            if (null !== $vector = $this->embeddings->cachedVector($song)) {
                $scored[] = ['song' => $song, 'score' => self::cosine($query, $vector)];
            }
        }
        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return \array_slice($scored, 0, $limit);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $na += $x * $x;
            $nb += $b[$i] * $b[$i];
        }

        return $na > 0 && $nb > 0 ? $dot / sqrt($na * $nb) : 0.0;
    }
}
