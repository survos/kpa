<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Service\ChordProInspector;
use App\Service\SongEmbeddingService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Retrieval over the catalog, shared by the chat and the MCP server.
 *
 * Songs are ranked by cosine similarity between the question's embedding and each song's
 * persisted vector (Song::$embedding, the same vectors Elasticsearch holds), with a small
 * boost when the question names the title. Every answer is built from database rows, so a
 * fact the chat repeats can be traced to a song page.
 */
final class SongFinder
{
    private const TITLE_BOOST = 0.15;

    /** @var array<string, string>|null song id => packed unit vector */
    private ?array $vectors = null;

    public function __construct(
        private readonly Connection $db,
        private readonly SongEmbeddingService $embeddings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<array<string, mixed>> best first
     */
    public function find(string $question, int $limit = 10, bool $withRecording = false, ?string $school = null): array
    {
        $limit = max(1, min(30, $limit));
        $query = self::unit($this->embeddings->embed($question));
        $needle = mb_strtolower(trim($question));

        $meta = $this->meta();
        $scores = [];
        foreach ($this->vectors() as $id => $packed) {
            $row = $meta[$id] ?? null;
            if (null === $row || ($withRecording && 0 === $row['recordings'])
                || (null !== $school && !str_contains(mb_strtolower((string) $row['school']), mb_strtolower($school)))) {
                continue;
            }
            $score = self::dot($query, unpack('g*', $packed));
            $title = mb_strtolower((string) $row['title']);
            if ('' !== $title && mb_strlen($title) > 3 && str_contains($needle, $title)) {
                $score += self::TITLE_BOOST;
            }
            $scores[$id] = $score;
        }
        arsort($scores);

        $hits = [];
        foreach (array_slice($scores, 0, $limit, true) as $id => $score) {
            $hits[] = $this->summary($meta[$id]) + ['score' => round($score, 3)];
        }

        return $hits;
    }

    /** @return list<array<string, mixed>> songs of one school (partial, case-insensitive), optionally one year */
    public function bySchool(string $school, ?int $year = null, int $limit = 50): array
    {
        $rows = array_filter($this->meta(), static fn (array $r) => str_contains(mb_strtolower((string) $r['school']), mb_strtolower(trim($school)))
            && (null === $year || (int) $r['year'] === $year));
        usort($rows, static fn (array $a, array $b) => [(int) $a['year'], $a['title']] <=> [(int) $b['year'], $b['title']]);

        return array_map($this->summary(...), array_slice(array_values($rows), 0, max(1, min(200, $limit))));
    }

    /** @return array<string, mixed>|null the song with plain lyrics, credits and playable recordings */
    public function get(string $id): ?array
    {
        $song = $this->db->fetchAssociative('SELECT id, title, school, year, writers, publisher, lyrics, featured_artist, recording_credits, musicians, description FROM song WHERE id = ?', [$id]);
        if (false === $song) {
            return null;
        }
        $recordings = [];
        foreach ($this->db->fetchAllAssociative('SELECT a.id, a.title, a.variant, f.duration, f.path, f.s3, f.relative_path, f.year FROM audio a JOIN file_asset f ON f.id = a.file_asset_id WHERE a.song_id = ? ORDER BY a.id', [$id]) as $r) {
            $recordings[] = array_filter([
                'id' => $r['id'],
                'variant' => $r['variant'] ?? 'recording',
                'seconds' => $r['duration'] ? (int) round((float) $r['duration']) : null,
                'year' => $r['year'] ? (int) $r['year'] : null,
                'aiGenerated' => self::isAi((string) $r['relative_path'].' '.$r['path'].' '.$r['variant']),
                'url' => $this->urls->generate('audio_file', ['id' => $r['id']], UrlGeneratorInterface::ABSOLUTE_URL),
            ], static fn ($v) => null !== $v);
        }

        return array_filter([
            'id' => $song['id'],
            'title' => $song['title'],
            'school' => $song['school'] ?: null,
            'year' => $song['year'] ? (int) $song['year'] : null,
            'writers' => $song['writers'] ?: null,
            'publisher' => $song['publisher'] ?: null,
            'featuredArtist' => $song['featured_artist'] ?: null,
            'recordingCredits' => $song['recording_credits'] ?: null,
            'musicians' => $song['musicians'] ?: null,
            'description' => $song['description'] ?: null,
            'subtitle' => self::directive((string) $song['lyrics'], 'subtitle'),
            'chorus' => self::plain(ChordProInspector::firstChorus($song['lyrics']) ?? ''),
            'lyrics' => self::plain((string) $song['lyrics']) ?: null,
            'url' => $this->urls->generate('song_show', ['songId' => $song['id']], UrlGeneratorInterface::ABSOLUTE_URL),
            'recordings' => $recordings,
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        $s = $this->db->fetchAssociative("SELECT count(*) songs, count(*) FILTER (WHERE coalesce(lyrics, '') <> '') with_lyrics,
            count(*) FILTER (WHERE EXISTS (SELECT 1 FROM audio a WHERE a.song_id = song.id)) with_recordings,
            min(year) first_year, max(year) last_year, count(DISTINCT lower(school)) schools FROM song");
        $top = $this->db->fetchAllKeyValue("SELECT school, count(*) FROM song WHERE coalesce(school, '') <> '' GROUP BY school ORDER BY 2 DESC LIMIT 15");

        return [
            'songs' => (int) $s['songs'],
            'withLyrics' => (int) $s['with_lyrics'],
            'withRecordings' => (int) $s['with_recordings'],
            'recordings' => (int) $this->db->fetchOne('SELECT count(*) FROM audio'),
            'years' => [(int) $s['first_year'], (int) $s['last_year']],
            'schools' => (int) $s['schools'],
            'topSchools' => array_map('intval', $top),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(array $row): array
    {
        return array_filter([
            'id' => $row['id'],
            'title' => $row['title'],
            'school' => $row['school'] ?: null,
            'year' => $row['year'] ? (int) $row['year'] : null,
            'writers' => $row['writers'] ?: null,
            'recordings' => $row['recordings'],
            'chorus' => mb_substr(self::plain(ChordProInspector::firstChorus($row['lyrics']) ?? self::firstLines((string) $row['lyrics'])), 0, 300) ?: null,
            'url' => $this->urls->generate('song_show', ['songId' => $row['id']], UrlGeneratorInterface::ABSOLUTE_URL),
        ], static fn ($v) => null !== $v && '' !== $v);
    }

    /** @return array<string, array<string, mixed>> */
    private function meta(): array
    {
        static $meta = null;

        return $meta ??= array_column($this->db->fetchAllAssociative('SELECT s.id, s.title, s.school, s.year, s.writers, s.lyrics,
            (SELECT count(*) FROM audio a WHERE a.song_id = s.id) AS recordings FROM song s'), null, 'id');
    }

    /** @return array<string, string> */
    private function vectors(): array
    {
        if (null === $this->vectors) {
            $this->vectors = [];
            foreach ($this->db->iterateKeyValue('SELECT id, embedding FROM song WHERE embedding IS NOT NULL') as $id => $json) {
                $v = json_decode((string) $json, true);
                if (is_array($v) && [] !== $v) {
                    $this->vectors[$id] = pack('g*', ...self::unit($v));
                }
            }
        }

        return $this->vectors;
    }

    /** @param array<float> $v @return list<float> */
    private static function unit(array $v): array
    {
        $v = array_values(array_map('floatval', $v));
        $norm = sqrt(array_sum(array_map(static fn (float $x) => $x * $x, $v))) ?: 1.0;

        return array_map(static fn (float $x) => $x / $norm, $v);
    }

    /** @param list<float> $a @param array<int, float> $b 1-based, from unpack() */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * $b[$i + 1];
        }

        return $sum;
    }

    public static function isAi(string $text): bool
    {
        return 1 === preg_match('/suno|\bai\b|ai recordings|ai arrangement|\(s\)/i', $text);
    }

    private static function directive(string $lyrics, string $name): ?string
    {
        return preg_match('/\{'.$name.':\s*([^}]*)\}/i', $lyrics, $m) ? trim($m[1]) : null;
    }

    /** ChordPro to plain text: no directives, no chords, at most one blank line in a row. */
    private static function plain(string $chordPro): string
    {
        $chordPro = html_entity_decode($chordPro, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(['/^\{[^}]*\}\s*$/m', '/\[[^\]]*\]/'], '', $chordPro);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $text));
    }

    private static function firstLines(string $lyrics): string
    {
        return implode("\n", array_slice(array_filter(explode("\n", self::plain($lyrics))), 0, 4));
    }
}
