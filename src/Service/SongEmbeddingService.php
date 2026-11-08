<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Song;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Survos\SearchBundle\Search\EmbeddingProfile;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SongEmbeddingService implements EmbeddingProviderInterface
{
    public const int DIMENSIONS = 768;
    private bool $modelVerified = false;
    public readonly EmbeddingProfile $profile;

    public function __construct(
        #[Autowire(service: 'ai.platform.ollama')] private PlatformInterface $platform,
        #[Autowire(service: 'kpa.ollama.client')] private HttpClientInterface $http,
        private EntityManagerInterface $em,
        #[Autowire(env: 'KPA_EMBED_MODEL')] string $model,
        #[Autowire(env: 'KPA_EMBED_REVISION')] string $revision,
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
        return $this->generate('search_query: '.$text);
    }

    private function generate(string $text): array
    {
        if (!$this->modelVerified) {
            $models = $this->http->request('GET', '/api/tags')->toArray()['models'] ?? [];
            $matches = array_filter($models, fn (array $m): bool => $m['name'] === $this->profile->model && $m['digest'] === $this->profile->revision);
            if (!$matches) {
                throw new \RuntimeException('Ollama model digest differs from KPA_EMBED_REVISION. Update the revision and regenerate embeddings before using this model.');
            }
            $this->modelVerified = true;
        }
        $result = $this->platform->invoke($this->profile->model, $text, ['truncate' => false])->getResult();
        $vector = $result instanceof VectorResult ? ($result->getContent()[0] ?? null)?->getData() : null;
        if (!self::validVector($vector)) {
            throw new \UnexpectedValueException('Expected a finite, nonzero 768-dimensional embedding.');
        }
        return $vector;
    }

    #[AsCommand('app:songs:embed', 'Persist missing or stale song embeddings; safely resume after interruption')]
    public function generateSongs(SymfonyStyle $io, #[Option('Maximum number of vectors to generate; zero means all')] int $limit = 0): int
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('Limit must be zero or positive.');
        }
        $generated = $reused = $flagged = 0;
        foreach ($this->em->createQuery('SELECT s FROM App\\Entity\\Song s ORDER BY s.id')->toIterable() as $song) {
            if (self::needsLyricsReview($song)) {
                ++$flagged;
                $io->writeln('Needs lyrics review (excluded from embeddings): '.$song->id);
                $this->em->getConnection()->update('song', ['embedding' => null, 'embedding_fingerprint' => null], ['id' => $song->id]);
                $this->em->detach($song);
                continue;
            }
            $text = self::text($song);
            if ($this->cachedVector($song) !== null) {
                ++$reused;
            } elseif ($text !== '') {
                $vector = $this->generate('search_document: '.$text);
                // Persist independently of indexing/workflow listeners. A later ES rebuild reads this cache.
                $this->em->getConnection()->update('song', [
                    'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
                    'embedding_fingerprint' => $this->profile->fingerprint($text),
                ], ['id' => $song->id]);
                ++$generated;
                if ($generated % 100 === 0) {
                    $io->writeln(sprintf('Persisted %d vectors.', $generated));
                }
            }
            $this->em->detach($song);
            if ($limit > 0 && $generated >= $limit) {
                break;
            }
        }
        $io->success(sprintf('Generated %d; reused %d cached embeddings; %d need lyrics review.', $generated, $reused, $flagged));
        return Command::SUCCESS;
    }
}
