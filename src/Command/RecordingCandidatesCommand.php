<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SongMatcher;
use Doctrine\DBAL\Connection;
use Survos\CommandBundle\Attribute\AsAgentTool;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Drafts recording candidates for songs that have none, from a Dropbox listing.
 *
 * Read-only: it writes a review file and changes no record. A candidate is not an
 * approved association (docs/SongsImport.md); a reviewer confirms it, and a public
 * link is created, before app:ingest-audio --dropbox-manifest imports it.
 */
final class RecordingCandidatesCommand
{
    private const AUDIO = ['mp3', 'm4a', 'wav', 'aif', 'aiff', 'flac'];
    private const PLAYABLE = ['mp3', 'm4a', 'wav'];
    /** Words of a school name that say nothing about which school it is. */
    private const GENERIC = ['elementary', 'school', 'schools', 'primary', 'middle', 'academy', 'county', 'summer',
        'the', 'and', 'of', 'es', 'co', 'va', 'nc', 'id', 'tn', 'md', 'dc', 'ny', 'ca', 'hi', 'class', 'grade',
        'write', 'center', 'day', 'country', 'public', 'charter', 'program', 'kids', 'children', 'virginia',
        'song', 'songs', 'music', 'world', 'university', 'audio', 'online', 'bass', 'concert', 'farm', 'know', 'secret',
        'club', 'arts', 'running', 'paul', 'river', 'front', 'turnaround', 'album', 'mixes', 'latest', 'recordings'];

    /** @var array<string, true> distinctive words of every school in the catalog */
    private array $schoolWords = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SongMatcher $matcher,
        private readonly Filesystem $filesystem,
    ) {
    }

    #[AsCommand('app:recordings:candidates', 'Draft recording candidates for songs without audio, for review')]
    #[AsAgentTool(description: "Draft recording candidates for catalog songs that have no audio: matches the Dropbox listing by normalized title, school in the folder path, year and title uniqueness, and writes candidates.json and candidates.csv for review (tier A strongest). Changes no record; imports nothing.", idempotent: true)]
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('rclone lsjson listing of the Dropbox Songs-Recordings tree')]
        string $inventory = 'var/recovery/2026-11-11/songs-recordings-inventory.json',
        #[Option('where to write the review files')]
        string $output = 'var/recovery/recording-candidates',
        #[Option('candidates kept per song')]
        int $perSong = 5,
    ): int {
        $files = $this->readInventory($inventory);
        $linked = $this->linkedFiles();
        $songs = $this->db->fetchAllAssociative('SELECT s.id, s.title, s.school, s.year,
            EXISTS (SELECT 1 FROM audio a WHERE a.song_id = s.id) AS has_audio FROM song s');

        $byKey = [];
        foreach ($songs as $song) {
            $byKey[$this->key((string) $song['title'])][] = $song;
            foreach ($this->schoolTokens((string) $song['school']) as $token) {
                $this->schoolWords[$token] = true;
            }
        }

        $index = [];
        $skippedLinked = 0;
        foreach ($files as $file) {
            if (isset($linked['id'][$file['ID']]) || isset($linked['nameSize'][mb_strtolower($file['Name']).'|'.$file['Size']])) {
                ++$skippedLinked;
                continue;
            }
            $key = $this->key($this->matcher->normalizeTitle($file['Name']));
            if ('' !== $key) {
                $index[$key][] = $file;
            }
        }

        $review = [];
        $tiers = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($songs as $song) {
            if ($song['has_audio']) {
                continue;
            }
            $key = $this->key((string) $song['title']);
            $sameTitle = $byKey[$key] ?? [];
            $candidates = [];
            foreach ($index[$key] ?? [] as $file) {
                $candidates[] = $this->assess($song, $file, $sameTitle, true);
            }
            if ([] === $candidates && str_word_count($key) >= 3) {
                // Near-exact titles only: a typo or a dropped word, never a different song.
                foreach ($index as $fileKey => $list) {
                    $fileKey = (string) $fileKey;
                    if (abs(strlen($fileKey) - strlen($key)) > 3 || levenshtein($fileKey, $key) > 2) {
                        continue;
                    }
                    foreach ($list as $file) {
                        $candidates[] = $this->assess($song, $file, $byKey[$fileKey] ?? [], false);
                    }
                }
            }
            if ([] === $candidates) {
                continue;
            }
            usort($candidates, static fn (array $a, array $b) => [$a['tier'], -$a['rank']] <=> [$b['tier'], -$b['rank']]);
            $best = $candidates[0]['tier'];
            ++$tiers[$best];
            $review[] = [
                'song' => ['id' => $song['id'], 'title' => $song['title'], 'school' => $song['school'], 'year' => $song['year'] ? (int) $song['year'] : null],
                'tier' => $best,
                'candidateCount' => count($candidates),
                'candidates' => array_map(static function (array $c) {
                    unset($c['rank']);

                    return $c;
                }, array_slice($candidates, 0, $perSong)),
            ];
        }
        usort($review, static fn (array $a, array $b) => [$a['tier'], $a['song']['school'] ?? '', $a['song']['title']] <=> [$b['tier'], $b['song']['school'] ?? '', $b['song']['title']]);

        $this->filesystem->mkdir($output);
        $this->filesystem->dumpFile($output.'/candidates.json', json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $csv = fopen($output.'/candidates.csv', 'w');
        fputcsv($csv, ['tier', 'song_id', 'title', 'school', 'year', 'candidates', 'best_path', 'best_size_mb', 'best_modified', 'evidence', 'flags', 'dropbox_id', 'approve'], escape: '');
        foreach ($review as $row) {
            $c = $row['candidates'][0];
            fputcsv($csv, [$row['tier'], $row['song']['id'], $row['song']['title'], $row['song']['school'], $row['song']['year'], $row['candidateCount'],
                $c['Path'], round($c['Size'] / 1048576, 1), substr($c['ModTime'], 0, 10), implode('; ', $c['evidence']), implode('; ', $c['flags']), $c['ID'], ''], escape: '');
        }
        fclose($csv);

        $without = count(array_filter($songs, static fn (array $s) => !$s['has_audio']));
        $io->table(['', 'count'], [
            ['audio files in the listing', count($files)],
            ['already linked (skipped)', $skippedLinked],
            ['songs without audio', $without],
            ['  with tier A candidates', $tiers['A']],
            ['  with tier B candidates', $tiers['B']],
            ['  with tier C candidates only', $tiers['C']],
            ['  with no candidate', $without - array_sum($tiers)],
        ]);
        $io->success(sprintf('Wrote %s/candidates.json and candidates.csv. Nothing was imported.', $output));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $sameTitle catalog songs sharing the file's title key
     *
     * @return array<string, mixed>
     */
    private function assess(array $song, array $file, array $sameTitle, bool $exact): array
    {
        $dir = dirname((string) $file['Path']);
        $evidence = [$exact ? 'exact normalized title' : 'near-exact title (edit distance ≤ 2)'];
        $flags = [];

        $school = $this->schoolInPath($song, $dir);
        if (null !== $school) {
            $evidence[] = sprintf('school "%s" in folder', $school);
        }

        preg_match_all('/(?<!\d)(?:19[89]\d|20[0-3]\d)(?!\d)/', $dir.' '.$file['Name'], $m);
        $years = array_values(array_unique(array_map('intval', $m[0])));
        $songYear = $song['year'] ? (int) $song['year'] : null;
        $yearConflict = false;
        if ($songYear && [] !== $years) {
            if (in_array($songYear, $years, true) || in_array($songYear - 1, $years, true) || in_array($songYear + 1, $years, true)) {
                $evidence[] = sprintf('year %d in path', $songYear);
            } else {
                $yearConflict = true;
                $flags[] = sprintf('year conflict: path says %s, song %d', implode('/', $years), $songYear);
            }
        }

        // Everything in the KPA Dropbox is KPA's, but its iTunes libraries also hold other
        // artists' albums ("The Last Laugh" by Mark Knopfler): same title, different song.
        $artist = null;
        if (preg_match('~(?:^|/)(?:iTunes Music|iTunes Media/Music)/([^/]+)/~i', $dir.'/', $m)
            && !preg_match('/kid ?pan ?alley|\bkpa\b|reisler|unknown artist/i', $m[1])
            && null === $school && [] === array_intersect($this->schoolTokens($m[1]), array_keys($this->schoolWords))) {
            $artist = $m[1];
            $flags[] = sprintf('in the iTunes folder of another artist ("%s")', $artist);
        }

        $elsewhere = null;
        if (null === $school) {
            $path = ' '.$this->pathKey($dir).' ';
            foreach (array_keys($this->schoolWords) as $word) {
                if (str_contains($path, ' '.$word.' ')) {
                    $elsewhere = $word;
                    $flags[] = sprintf('folder names another school ("%s")', $word);
                    break;
                }
            }
        }

        $others = array_filter($sameTitle, static fn (array $s) => $s['id'] !== $song['id']);
        if ([] === $others) {
            $evidence[] = 'title unique in catalog';
        } else {
            $otherSchoolMatch = array_filter($others, fn (array $s) => null !== $this->schoolInPath($s, $dir));
            $flags[] = sprintf('%d other catalog song(s) share this title%s', count($others), $otherSchoolMatch ? ', one matches this folder too' : '');
        }

        $ext = strtolower(pathinfo((string) $file['Name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::PLAYABLE, true)) {
            $flags[] = $ext.' is not importable (mp3, m4a, wav only)';
        }
        if (preg_match('/\bstems?\b|-st\.wav$|\bbounce\b|\btake ?\d*\b|\bscratch\b/i', $file['Path'])) {
            $flags[] = 'looks like a stem or working take';
        }
        if (preg_match('/\bsuno\b|\bai (?:recording|version)s?\b|\(s\)/i', $file['Path'])) {
            $flags[] = 'AI-generated (Suno)';
        }

        $unique = [] === $others || (isset($otherSchoolMatch) && [] === $otherSchoolMatch && null !== $school);
        $tier = match (true) {
            null !== $artist => 'C',
            $exact && null !== $school && !$yearConflict && $unique => 'A',
            $exact && !$yearConflict && [] === $others && null === $elsewhere => 'B',
            default => 'C',
        };

        $rank = (null !== $school ? 4 : 0) + (in_array($ext, ['mp3', 'm4a'], true) ? 2 : 0)
            + (preg_match('/latest mixes|itunes|public/i', $file['Path']) ? 1 : 0)
            - (count($flags) * 3);

        return [
            'tier' => $tier,
            'rank' => $rank,
            'Path' => $file['Path'],
            'Name' => $file['Name'],
            'Size' => $file['Size'],
            'ModTime' => $file['ModTime'],
            'ID' => $file['ID'],
            'evidence' => $evidence,
            'flags' => $flags,
        ];
    }

    /** The first distinctive word of the song's school found in the folder path, or null. */
    private function schoolInPath(array $song, string $dir): ?string
    {
        $path = ' '.$this->pathKey($dir).' ';
        $tokens = $this->schoolTokens((string) $song['school']);
        foreach ($tokens as $token) {
            if (str_contains($path, ' '.$token) ) {
                return $token;
            }
        }
        $prefix = substr((string) $song['id'], 0, 4);
        if (1 === preg_match('/^[a-z]{4}$/', $prefix) && !in_array($prefix, ['temp', 'gmu7'], true) && preg_match('/\b'.$prefix.'[a-z]*\b/', $path)
            && $tokens !== [] && str_starts_with(reset($tokens), $prefix)) {
            return $prefix;
        }

        return null;
    }

    /** @return list<string> */
    private function schoolTokens(string $school): array
    {
        return array_values(array_filter(explode(' ', $this->pathKey($school)), static fn (string $t) => strlen($t) >= 4 && !in_array($t, self::GENERIC, true) && !ctype_digit($t)));
    }

    /** Like key(), but a hyphen separates words: "Santa Barbara-Peabody" names Peabody. */
    private function pathKey(string $text): string
    {
        return $this->key(str_replace(['-', '_'], ' ', $text));
    }

    private function key(string $text): string
    {
        return SongMatcher::matchKey($text);
    }

    /** @return list<array<string, mixed>> audio files, read line by line (the listing may lack its closing bracket) */
    private function readInventory(string $path): array
    {
        $files = [];
        foreach (new \SplFileObject($path) as $line) {
            $row = json_decode(rtrim(trim((string) $line), ','), true);
            if (is_array($row) && !($row['IsDir'] ?? true) && in_array(strtolower(pathinfo((string) $row['Name'], PATHINFO_EXTENSION)), self::AUDIO, true)) {
                $files[] = $row;
            }
        }

        return $files;
    }

    /** @return array{id: array<string, true>, nameSize: array<string, true>} Dropbox ids and name|size of files already linked */
    private function linkedFiles(): array
    {
        $linked = ['id' => [], 'nameSize' => []];
        foreach ($this->db->fetchAllAssociative('SELECT filename, size, probed_data FROM file_asset') as $row) {
            $data = json_decode((string) $row['probed_data'], true) ?: [];
            if ($id = $data['archive']['fileId'] ?? null) {
                $linked['id'][$id] = true;
            }
            $original = $data['format']['filename'] ?? $data['archive']['sourcePath'] ?? $row['filename'];
            $size = $data['format']['size'] ?? $row['size'];
            $linked['nameSize'][mb_strtolower(basename((string) $original)).'|'.$size] = true;
        }

        return $linked;
    }
}
