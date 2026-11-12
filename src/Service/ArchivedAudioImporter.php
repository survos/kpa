<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Audio;
use App\Entity\FileAsset;
use App\Entity\Song;
use Doctrine\ORM\EntityManagerInterface;

/** Reconnect cached S3 sidecars to the existing catalog without reading audio bytes. */
final class ArchivedAudioImporter
{
    public function __construct(private EntityManagerInterface $em, private SongMatcher $matcher) {}

    public function import(string $manifest, bool $dry = true, int $limit = 0, ?string $reviewedMatches = null): array
    {
        $rows = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $reviewed = $reviewedMatches === null ? [] : json_decode((string) file_get_contents($reviewedMatches), true, 512, JSON_THROW_ON_ERROR);
        $songsById = [];
        $songs = [];
        foreach ($this->em->getRepository(Song::class)->findAll() as $song) {
            $songsById[$song->id] = $song;
            $songs[SongMatcher::matchKey($this->matcher->normalizeTitle($song->title ?? ''))][] = $song;
        }
        $existing = array_fill_keys(array_column($this->em->getConnection()->fetchAllAssociative('SELECT s3 FROM file_asset WHERE s3 IS NOT NULL'), 's3'), true);
        $report = ['created' => 0, 'existing' => 0, 'unmatched' => [], 'ambiguous' => [], 'yearConflict' => [], 'matches' => []];
        foreach ($rows as $key => $row) {
            if ($limit > 0 && count($report['matches']) >= $limit) { break; }
            $objectKey = $row['archive']['objectKey'] ?? '';
            if (!str_ends_with($objectKey, '.mp3') || str_contains($objectKey, '..')) {
                throw new \InvalidArgumentException('Expected an MP3 archive object key in each sidecar.');
            }
            $url = 'https://kpa-songs.s3.amazonaws.com/'.implode('/', array_map('rawurlencode', explode('/', $objectKey)));
            if (isset($existing[$url])) { ++$report['existing']; continue; }
            $tags = $row['probedData']['format']['tags'] ?? [];
            $date = (string) ($tags['date'] ?? '');
            $year = preg_match('/^(19|20)\d{2}$/', $date) ? (int) $date : null;
            $review = $reviewed[$objectKey] ?? null;
            if ($review !== null) {
                $song = $songsById[$review['songId'] ?? ''] ?? null;
                if (!$song || ($review['expectedTitle'] ?? null) !== $song->title
                    || ($review['sourceFilename'] ?? null) !== ($row['filename'] ?? null)
                    || trim($review['reason'] ?? '') === '') {
                    throw new \InvalidArgumentException('Stale or incomplete reviewed association: '.$objectKey);
                }
            } else {
                // Historical folder matches are a fallback only; a reviewed source-file
                // association can correct old fuzzy matches without moving S3 objects.
                $title = str_replace('-', ' ', basename(dirname($objectKey)));
                $candidates = $songs[SongMatcher::matchKey($title)] ?? [];
                if (!$candidates) { $report['unmatched'][] = $objectKey; continue; }
                if ($year !== null) {
                    $candidates = array_values(array_filter($candidates, static fn (Song $s) => $s->year === null || $s->year === $year));
                    if (!$candidates) { $report['yearConflict'][] = $objectKey; continue; }
                }
                if (count($candidates) !== 1) { $report['ambiguous'][] = $objectKey; continue; }
                $song = $candidates[0];
            }
            $variant = self::variant($row['filename'] ?? basename($objectKey), $row['dirname'] ?? '');
            $asset = new FileAsset(
                path: $row['path'] ?? $url,
                relativePath: $objectKey,
                filename: $row['filename'] ?? basename($objectKey),
                extension: 'mp3', dirname: $row['dirname'] ?? null,
                size: isset($row['size']) ? (int) $row['size'] : null,
                modifiedTime: isset($row['modifiedTime']) ? (int) $row['modifiedTime'] : null,
                type: 'audio', school: $review !== null ? ($review['recordingSchool'] ?? null) : $song->school, year: $year ?? $song->year,
                mimeType: 'audio/mpeg', duration: isset($row['duration']) ? (float) $row['duration'] : null,
                probedData: $row['probedData'] ?? null,
            );
            $asset->s3 = $url;
            $asset->marking = 'uploaded';
            if (!$dry) {
                $this->em->persist($asset);
                $this->em->persist(new Audio($asset, $song, (string) $song->title, 'mp3', $asset->size, $variant));
            }
            $existing[$url] = true;
            $report['matches'][] = ['songId' => $song->id, 'key' => $objectKey, 'basis' => $review['reason'] ?? 'unique title and compatible recording year'];
            ++$report['created'];
        }
        if (!$dry) { $this->em->flush(); }
        return $report;
    }
    /** Import reviewed Dropbox metadata with already-public, non-expiring playback links. */
    public function importDropbox(string $manifest, bool $dry = true): array
    {
        $rows = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $existing = array_fill_keys($this->em->getConnection()->fetchFirstColumn('SELECT path FROM file_asset'), true);
        $report = ['created' => 0, 'existing' => 0, 'matches' => []];
        foreach ($rows as $row) {
            $link = $row['link'];
            $url = $link['url'];
            $parts = parse_url($url);
            if (($link['audience'] ?? '') !== 'public' || !($link['allow_download'] ?? false)
                || ($link['password_protected'] ?? false) || !empty($link['expires'])
                || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'www.dropbox.com'
                || !preg_match('~^/(scl/fi|s)/~', $parts['path'] ?? '')
                || !str_starts_with($row['ID'] ?? '', 'id:') || empty($row['evidence'])) {
                throw new \InvalidArgumentException('Expected a reviewed recording with an existing public Dropbox file link.');
            }
            parse_str($parts['query'] ?? '', $query);
            unset($query['dl']);
            $query['raw'] = '1';
            $url = 'https://www.dropbox.com'.$parts['path'].'?'.http_build_query($query);
            if (isset($existing[$url])) { ++$report['existing']; continue; }
            $song = $this->em->find(Song::class, $row['song']['id']);
            if (!$song || $song->title !== $row['song']['title'] || $song->school !== $row['song']['school']) {
                throw new \InvalidArgumentException('Stale reviewed Dropbox song association.');
            }
            $extension = strtolower(pathinfo($row['Name'], PATHINFO_EXTENSION));
            if (!in_array($extension, ['mp3', 'm4a'], true)) {
                throw new \InvalidArgumentException('Only reviewed MP3 and M4A recordings are supported.');
            }
            $asset = new FileAsset(path: $url, relativePath: $row['Path'], filename: $row['Name'],
                extension: $extension, dirname: dirname($row['Path']), size: $row['Size'],
                modifiedTime: strtotime($row['ModTime']), type: 'audio', school: $song->school,
                mimeType: $extension === 'mp3' ? 'audio/mpeg' : 'audio/mp4',
                probedData: ['archive' => ['provider' => 'dropbox', 'fileId' => $row['ID'],
                    'sourcePath' => $row['Path'], 'matchEvidence' => $row['evidence'], 'publicLink' => $link]]);
            if (!$dry) {
                $this->em->persist($asset);
                $this->em->persist(new Audio($asset, $song, (string) $song->title, $extension, $asset->size,
                    self::variant($row['Name'], dirname($row['Path']))));
            }
            $existing[$url] = true;
            ++$report['created'];
            $report['matches'][] = ['songId' => $song->id, 'fileId' => $row['ID']];
        }
        if (!$dry) { $this->em->flush(); }
        return $report;
    }

    private static function variant(string $filename, string $dirname): ?string
    {
        $text = strtolower($filename.' '.$dirname);
        foreach (['ai' => '/\bai\b/', 'instrumental' => '/\b(instrumental|backing track)\b/',
            'gtr' => '/\b(gtr|guitar only)\b/', 'gv' => '/\b(gv|guide vocal|guitar vocal)\b/',
            'wf' => '/\bwf\b/', 'live' => '/\b(live|concert)\b/'] as $variant => $pattern) {
            if (preg_match($pattern, $text)) { return $variant; }
        }
        return null;
    }

}
