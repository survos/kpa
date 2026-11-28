<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Catalog\EpisodeCatalog;
use App\Catalog\SongFinder;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/** Read-only MCP tools over the Kid Pan Alley song catalog and podcast. The chat calls the same methods. */
final readonly class SongTools
{
    public function __construct(private SongFinder $finder, private EpisodeCatalog $episodes)
    {
    }

    /**
     * Find Kid Pan Alley songs closest in meaning to a question or theme, for example "songs about
     * winter holidays", "a song about a grandmother" or "friendship at a new school". Returns title,
     * school, year, writers, the number of recordings, the start of the chorus and a link, best first.
     *
     * @param string      $question      What to look for, in plain words
     * @param int         $limit         Maximum number of songs (1 to 30)
     * @param bool        $withRecording Only songs that have at least one recording
     * @param string|null $school        Only songs of this school (part of its name)
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'find_songs', annotations: new ToolAnnotations(title: 'Find songs by meaning', readOnlyHint: true, openWorldHint: false))]
    public function find(string $question, int $limit = 10, bool $withRecording = false, ?string $school = null): array
    {
        try {
            return $this->finder->find($question, $limit, $withRecording, $school);
        } catch (\RuntimeException|\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            // the embedding service (Ollama) is down or AI is paused: say so, and offer the tool that still works
            throw new ToolCallException('Search by meaning is unavailable right now ('.$e->getMessage().'). search_lyrics still works for exact words.', previous: $e);
        }
    }

    /**
     * Get one song: its credits, school and year, the subtitle naming the class, the chorus, the full
     * lyrics, and its recordings with playable URLs (aiGenerated marks Suno or AI arrangements).
     *
     * @param string $id Song id, as returned by find_songs or songs_by_school
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_song', annotations: new ToolAnnotations(title: 'Get a song', readOnlyHint: true, openWorldHint: false))]
    public function get(string $id): array
    {
        return $this->finder->get($id) ?? throw new ToolCallException(sprintf('No song with id "%s". Ids come from find_songs, search_lyrics or songs_by_school.', $id));
    }

    /**
     * List the songs written with one school or program, optionally in one year, oldest first.
     *
     * @param string   $school Part of the school name, for example "Rappahannock" or "Grymes"
     * @param int|null $year   Only this catalog year
     * @param int      $limit  Maximum number of songs (1 to 200)
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'songs_by_school', annotations: new ToolAnnotations(title: 'Songs of a school', readOnlyHint: true, openWorldHint: false))]
    public function bySchool(string $school, ?int $year = null, int $limit = 50): array
    {
        return $this->finder->bySchool($school, $year, $limit);
    }

    /**
     * Exact catalog counts: songs, songs with lyrics, songs with recordings, recordings, the range of
     * years, the number of schools and the schools with the most songs. Use it for "how many" questions.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'catalog_stats', annotations: new ToolAnnotations(title: 'Catalog counts', readOnlyHint: true, openWorldHint: false))]
    public function stats(): array
    {
        return $this->finder->stats();
    }

    /**
     * Find songs whose lyrics or title contain exact words, ignoring case and punctuation, for example
     * "old man winter" or "give thanks". Returns each song with the lines that contain the phrase.
     * Use it to identify a song from a remembered line; use find_songs for a theme or meaning.
     *
     * @param string $phrase The words to look for, at least three letters
     * @param int    $limit  Maximum number of songs (1 to 50)
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'search_lyrics', annotations: new ToolAnnotations(title: 'Search lyrics for words', readOnlyHint: true, openWorldHint: false))]
    public function searchLyrics(string $phrase, int $limit = 10): array
    {
        try {
            return $this->finder->findPhrase($phrase, $limit);
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }

    /**
     * List the episodes of the Kid Pan Alley podcast, newest first: title, summary, length, the songs
     * they play, and links to the page, the audio and the WebVTT transcript.
     *
     * @return list<array<string, mixed>>
     */
    #[McpTool(name: 'list_episodes', annotations: new ToolAnnotations(title: 'Podcast episodes', readOnlyHint: true, openWorldHint: false))]
    public function listEpisodes(): array
    {
        return array_map(fn (array $e) => [
            'slug' => $e['slug'],
            'title' => $e['title'],
            'summary' => $e['summary'],
            'published' => $e['published'],
            'seconds' => $e['seconds'],
            'songs' => array_map(static fn (array $s) => $s['title'].' ('.$s['school'].($s['year'] ? ', '.$s['year'] : '').')', $e['songs']),
        ] + $this->episodes->links($e), $this->episodes->all());
    }

    /**
     * Get one podcast episode: its chapters (start in seconds), the songs with their excerpt times and
     * credits, links, and the full transcript ("PaulBot: ..." lines, "[Music: ...]" for each excerpt).
     *
     * @param string $slug Episode slug, as returned by list_episodes
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_episode', annotations: new ToolAnnotations(title: 'Get an episode', readOnlyHint: true, openWorldHint: false))]
    public function getEpisode(string $slug): array
    {
        $episode = $this->episodes->get($slug);
        if (null === $episode) {
            throw new ToolCallException(sprintf('No episode "%s". Call list_episodes for the slugs.', $slug));
        }
        unset($episode['bytes']);

        return $episode + $this->episodes->links($episode) + ['transcript' => $this->episodes->transcript($slug)];
    }
}
