<?php

declare(strict_types=1);

namespace App\Episode;

use App\Entity\Audio;
use App\Entity\Song;
use App\Service\ChordProInspector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a song's card from the catalog: every fact the hosts may say comes from the
 * Song and the Audio the excerpt is cut from, nothing typed by hand.
 */
final readonly class SongCardFactory
{
    // the house credit on most songs (open question in docs/themed-episodes.md): kept off the air
    private const string HOUSE_CREDIT = 'K.P. Alley';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param string $recording the recording's public URL (FileAsset::$s3), as listed by app:episode:songs
     */
    public function card(string $songId, string $recording, float $start, float $end, string $excerptLyrics = ''): SongCard
    {
        $song = $this->em->find(Song::class, $songId) ?? throw new \InvalidArgumentException(sprintf('No song "%s" in the catalog.', $songId));
        $audio = $this->em->getRepository(Audio::class)->createQueryBuilder('a')
            ->join('a.fileAsset', 'f')->where('a.song = :song')->andWhere('f.s3 = :url')
            ->setParameter('song', $song)->setParameter('url', $recording)
            ->getQuery()->getOneOrNullResult()
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a recording of "%s".', $recording, $song->title));

        return new SongCard($song->id, (string) $song->title, self::facts($song, $audio), $recording, self::performance($audio), $start, $end, $excerptLyrics);
    }

    public static function facts(Song $song, Audio $audio): string
    {
        $facts = [];
        if (null !== $credit = self::subtitle($song->lyrics)) {
            $facts[] = "Credited on the lyric sheet: {$credit}.";
        }
        $writers = trim(implode('; ', array_filter(array_map(trim(...), explode(';', (string) $song->writers)), static fn (string $w) => '' !== $w && self::HOUSE_CREDIT !== $w)));
        if ('' !== $writers) {
            $facts[] = "Writers in the catalog: {$writers}.";
        }
        $facts[] = sprintf('School: %s%s.', $song->school ?? 'unknown', $song->year ? ", {$song->year}" : '');
        if (null !== $song->date) {
            $facts[] = 'Catalog date: '.$song->date->format('j F Y').'.';
        }
        if (null !== $song->publisher && '' !== trim($song->publisher)) {
            $facts[] = "Published by {$song->publisher}.";
        }
        if (null !== $lyrics = ChordProInspector::plainLyrics($song->lyrics)) {
            $facts[] = "Lyrics:\n".$lyrics;
        }
        $facts[] = sprintf('The recording played: %s, from the archive folder "%s".', self::performance($audio), self::folder($audio));

        return implode("\n", $facts);
    }

    public static function performance(Audio $audio): string
    {
        $path = strtolower($audio->fileAsset->path);

        return match (true) {
            str_contains($path, 'suno') || str_contains($path, 'ai recordings') => 'AI-generated arrangement',
            'gv' === $audio->variant => 'guitar and vocal version',
            'gtr' === $audio->variant => 'guitar-only version',
            'live' === $audio->variant || str_contains($path, 'concert') => 'concert recording',
            'wf' === $audio->variant => 'work-in-progress mix',
            str_contains($path, 'mix') => 'studio mix',
            default => 'recording',
        };
    }

    private static function folder(Audio $audio): string
    {
        $dirs = explode('/', \dirname($audio->fileAsset->path));

        return implode('/', \array_slice($dirs, -2));
    }

    private static function subtitle(?string $lyrics): ?string
    {
        if (null === $lyrics || !preg_match('/\{subtitle:\s*([^}]+)\}/i', $lyrics, $m)) {
            return null;
        }

        return trim(html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }
}
