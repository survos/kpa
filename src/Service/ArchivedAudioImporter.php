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

    public function import(string $manifest, bool $dry = true, int $limit = 0): array
    {
        $rows = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        $songs = [];
        foreach ($this->em->getRepository(Song::class)->findAll() as $song) {
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
            // Use the title directory established by the original matching pipeline.
            // Do not silently collapse same-title songs or use fuzzy matches here.
            $title = str_replace('-', ' ', basename(dirname($objectKey)));
            $candidates = $songs[SongMatcher::matchKey($title)] ?? [];
            if (!$candidates) { $report['unmatched'][] = $objectKey; continue; }
            $tags = $row['probedData']['format']['tags'] ?? [];
            $date = (string) ($tags['date'] ?? '');
            $year = preg_match('/^(19|20)\d{2}$/', $date) ? (int) $date : null;
            if ($year !== null) {
                $candidates = array_values(array_filter($candidates, static fn (Song $s) => $s->year === null || $s->year === $year));
                if (!$candidates) { $report['yearConflict'][] = $objectKey; continue; }
            }
            if (count($candidates) !== 1) { $report['ambiguous'][] = $objectKey; continue; }
            $song = $candidates[0];
            $variant = str_contains(basename($objectKey), '-gv.') ? 'gv' : null;
            $asset = new FileAsset(
                path: $row['path'] ?? $url,
                relativePath: $objectKey,
                filename: $row['filename'] ?? basename($objectKey),
                extension: 'mp3', dirname: $row['dirname'] ?? null,
                size: isset($row['size']) ? (int) $row['size'] : null,
                modifiedTime: isset($row['modifiedTime']) ? (int) $row['modifiedTime'] : null,
                type: 'audio', school: $song->school, year: $year ?? $song->year,
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
            $report['matches'][] = ['songId' => $song->id, 'key' => $objectKey];
            ++$report['created'];
        }
        if (!$dry) { $this->em->flush(); }
        return $report;
    }
}
