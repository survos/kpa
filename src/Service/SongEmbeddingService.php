<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Song;
use Doctrine\ORM\EntityManagerInterface;
use Survos\AiEmbedBundle\EmbeddingProviderInterface as VectorProvider;
use Survos\AiEmbedBundle\EmbeddingService;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Survos\SearchBundle\Search\EmbeddingProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Song vectors: generated and cached by survos/ai-embed-bundle (keyed by model profile and
 * exact input, in a DuckDB file outside the catalog database), projected onto Song::$embedding,
 * which the Elasticsearch index reads. Search queries go to the provider directly: the
 * cache file is locked by one process at a time, and a web request must not wait for it.
 */
final class SongEmbeddingService implements EmbeddingProviderInterface, \App\Catalog\QueryEmbedder
{
    public const int DIMENSIONS = 768;
    public readonly EmbeddingProfile $profile;

    public function __construct(
        private EmbeddingService $vectors,
        private VectorProvider $provider,
        private EntityManagerInterface $em,
        #[Autowire(env: 'KPA_EMBED_MODEL')] string $model,
        #[Autowire(env: 'KPA_EMBED_REVISION')] string $revision,
        #[Autowire(env: 'bool:KPA_AI_ENABLED')] public readonly bool $enabled = false,
    ) {
        $this->profile = new EmbeddingProfile($model, self::DIMENSIONS, $revision, 'song-v3-clean-text');
    }

    public static function text(Song $song): string
    {
        $lyrics = self::cleanLyrics($song->lyrics ?? '');
        return trim(preg_replace('/\s+/u', ' ', implode("\n", [
            $song->title ?? '', $song->description ?? '', $song->school ?? '',
            (string) $song->year, $song->writers ?? '', $lyrics,
        ])) ?? '');
    }

    public static function cleanLyrics(string $lyrics): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/\{[^}]*\}|\[[^\]]*\]/u', '', html_entity_decode($lyrics, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '') ?? '');
    }

    public static function needsLyricsReview(Song $song): bool
    {
        // Recovery export includes draft/inspiration pages in the lyrics column.
        // Preserve the source, but keep unusually long records out of semantic search pending review.
        return mb_strlen($song->lyrics ?? '') > 6000;
    }

    public function cachedVector(Song $song): ?array
    {
        if (self::needsLyricsReview($song)) {
            return null;
        }
        if ($song->embeddingFingerprint !== $this->profile->fingerprint(self::text($song))) {
            return null;
        }
        return self::validVector($song->embedding) ? $song->embedding : null;
    }

    public static function validVector(?array $vector): bool
    {
        return $vector !== null && array_is_list($vector) && count($vector) === self::DIMENSIONS
            && array_all($vector, static fn ($v): bool => (is_float($v) || is_int($v)) && is_finite((float) $v))
            && array_any($vector, static fn ($v): bool => $v != 0);
    }

    public function embed(string $text): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('AI is paused in this app (KPA_AI_ENABLED=0).');
        }
        // the provider checks the installed model's digest against the pinned revision
        $vector = $this->provider->embed([$this->vectors->profile->input($text, 'query')], $this->vectors->profile)[0] ?? null;
        if (!self::validVector($vector)) {
            throw new \UnexpectedValueException('Expected a finite, nonzero 768-dimensional embedding.');
        }

        return $vector;
    }

    #[AsCommand('app:songs:embed', 'Embed songs through the vector cache and project the vectors onto the songs; safely resumes')]
    public function generateSongs(SymfonyStyle $io, #[Option('Maximum number of vectors to generate; zero means all')] int $limit = 0): int
    {
        if (!$this->enabled) {
            $io->error('AI is paused in this app (KPA_AI_ENABLED=0).');
            return Command::FAILURE;
        }
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be zero or positive.');
        }

        // 1. one cache request per song; a vector already projected and current seeds the cache, no model call
        $requests = [];
        $hits = $seeded = $flagged = 0;
        foreach ($this->em->createQuery('SELECT s FROM App\\Entity\\Song s ORDER BY s.id')->toIterable() as $song) {
            if (self::needsLyricsReview($song)) {
                ++$flagged;
                $io->writeln('Needs lyrics review (excluded from embeddings): '.$song->id);
                $this->em->getConnection()->update('song', ['embedding' => null, 'embedding_fingerprint' => null], ['id' => $song->id]);
            } elseif ('' !== $text = self::text($song)) {
                $request = $this->vectors->ensureEmbedding($text);
                if ($request['cacheHit']) {
                    ++$hits;
                } elseif (null !== $projected = $this->cachedVector($song)) {
                    $this->vectors->cache->complete([$request], [$projected], $this->vectors->profile);
                    ++$seeded;
                }
                $requests[$song->id] = [$request['id'], $this->profile->fingerprint($text), $song->embeddingFingerprint];
            }
            $this->em->detach($song);
        }

        // 2. generate what the cache does not have yet
        $generated = 0;
        do {
            $batch = $limit > 0 ? min(1000, $limit - $generated) : 1000;
            $completed = $batch > 0 ? $this->vectors->consume(16, $batch)['completed'] : 0;
            $generated += $completed;
            if ($completed > 0) {
                $io->writeln(sprintf('Generated %d vectors.', $generated));
            }
        } while ($completed > 0 && ($limit === 0 || $generated < $limit));

        // 3. project the cached vectors onto the songs whose projection is missing or stale
        $projected = $pending = 0;
        foreach ($requests as $id => [$requestId, $fingerprint, $current]) {
            if ($current === $fingerprint) {
                continue;
            }
            if (null === $vector = $this->vectors->cache->vector($requestId, $this->vectors->profile)) {
                ++$pending;
                continue;
            }
            // persisted independently of indexing/workflow listeners; a later ES rebuild reads it
            $this->em->getConnection()->update('song', ['embedding' => json_encode($vector, JSON_THROW_ON_ERROR), 'embedding_fingerprint' => $fingerprint], ['id' => $id]);
            ++$projected;
        }
        $this->vectors->cache->close();

        $io->success(sprintf('%d songs: %d cache hits, %d seeded from the songs, %d generated, %d projected, %d still pending; %d need lyrics review.', \count($requests), $hits, $seeded, $generated, $projected, $pending, $flagged));

        return Command::SUCCESS;
    }
}
