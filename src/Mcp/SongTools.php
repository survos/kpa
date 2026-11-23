<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Catalog\SongFinder;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Read-only MCP tools over the Kid Pan Alley song catalog. The chat calls the same methods. */
final readonly class SongTools
{
    public function __construct(private SongFinder $finder)
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
        return $this->finder->find($question, $limit, $withRecording, $school);
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
        return $this->finder->get($id) ?? ['error' => sprintf('No song with id "%s".', $id)];
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
}
