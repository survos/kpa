<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The published podcast episodes: one folder per episode in KPA_EPISODES_DIR, written by
 * app:episode:publish (episode.json, <slug>.mp3, <slug>.vtt). Read by the podcast pages and MCP.
 */
final readonly class EpisodeCatalog
{
    public const SLUG = '[a-z0-9][a-z0-9-]{1,80}';

    public function __construct(
        #[Autowire(env: 'resolve:KPA_EPISODES_DIR')] private string $dir,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        $episodes = [];
        foreach (glob($this->dir.'/*/episode.json') ?: [] as $file) {
            $episodes[] = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        }
        usort($episodes, static fn (array $a, array $b) => $b['published'] <=> $a['published']);

        return $episodes;
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        $file = $this->file($slug, 'episode.json');

        return null === $file ? null : json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** The path of one of an episode's files, or null when there is no such episode or file. */
    public function file(string $slug, string $name): ?string
    {
        $path = $this->dir.'/'.$slug.'/'.$name;

        return preg_match('/^'.self::SLUG.'$/', $slug) && is_file($path) ? $path : null;
    }

    /** @return array<string, string> page, audio, WebVTT transcript (vtt) and feed URLs, absolute */
    public function links(array $episode): array
    {
        $version = ['slug' => $episode['slug'], 'v' => (new \DateTimeImmutable($episode['published']))->format('U')];

        return [
            'page' => $this->urls->generate('episode_show', ['slug' => $episode['slug']], UrlGeneratorInterface::ABSOLUTE_URL),
            'audio' => $this->urls->generate('episode_mp3', $version, UrlGeneratorInterface::ABSOLUTE_URL),
            'vtt' => $this->urls->generate('episode_vtt', $version, UrlGeneratorInterface::ABSOLUTE_URL),
            'feed' => $this->urls->generate('episode_feed', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /** The spoken text of an episode, one "Speaker: text" line per cue, from its WebVTT transcript. */
    public function transcript(string $slug): ?string
    {
        $file = $this->file($slug, $slug.'.vtt');
        if (null === $file) {
            return null;
        }
        $lines = [];
        foreach (preg_split('/\R\R+/', (string) file_get_contents($file)) as $cue) {
            $rows = preg_split('/\R/', trim($cue));
            $text = end($rows);
            if (!str_contains($cue, '-->') || false === $text) {
                continue;
            }
            $lines[] = preg_match('/^<v ([^>]+)>(.*)$/', $text, $m) ? $m[1].': '.$m[2] : $text;
        }

        return implode("\n", $lines);
    }
}
