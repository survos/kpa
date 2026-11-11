<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Audio;
use App\Entity\FileAsset;
use App\Entity\Song;
use App\Repository\SongRepository;
use App\Service\SongMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\MimeTypes;

#[AsCommand('app:ingest-audio', 'Walk var/data/ song directories and create FileAsset + Audio records from audio files')]
final class IngestAudioCommand
{
    private const AUDIO_EXTENSIONS = ['mp3', 'wav', 'aif', 'aiff', 'flac', 'm4a', 'ogg', 'wma'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SongRepository $songRepository,
        private readonly SongMatcher $songMatcher,
        private readonly \App\Service\ArchivedAudioImporter $archivedAudioImporter,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('staging directory containing <slug>/ song dirs')]
        string $dir = 'var/data',
        #[Option('only process first N files (0 = all)')]
        int $limit = 0,
        #[Option('do not flush; show what would happen')]
        bool $dry = false,
        #[Option('delete existing Audio + FileAsset records before import')]
        bool $reset = false,
        #[Option('audio scan JSONL used as the source of file facts (from import:dir)')]
        string $jsonl = 'data/audio.jsonl',
        #[Option('Cached S3 JSON sidecars; reconnect remote recordings without local MP3 files')]
        ?string $s3Manifest = null,
    ): int {
        if ($s3Manifest !== null) {
            if ($reset) {
                $io->error('--reset is not supported for archived recordings.');
                return Command::FAILURE;
            }
            $report = $this->archivedAudioImporter->import($s3Manifest, $dry, $limit);
            $io->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }
        $absDir = str_starts_with($dir, '/') ? $dir : $this->projectDir . '/' . ltrim($dir, '/');
        if (!is_dir($absDir)) {
            $io->error(sprintf('Not a directory: %s', $absDir));
            return Command::FAILURE;
        }

        if ($reset && !$dry) {
            $this->resetRecords($io);
        }

        $this->songMatcher->warmCache();
        $existingPaths = $this->loadExistingPaths();
        $songCache = [];

        // The symlinks in var/data point at the Dropbox FUSE mount, where every stat
        // and every content read is expensive. import:dir already recorded size,
        // mtime, readability and mime for each file, so read those from the scan
        // JSONL and never touch the mount here.
        $absJsonl = str_starts_with($jsonl, '/') ? $jsonl : $this->projectDir . '/' . ltrim($jsonl, '/');
        $scan = $this->loadScan($absJsonl);
        if ($scan === []) {
            $io->warning(sprintf(
                'No file records loaded from %s — falling back to stat()ing each file over FUSE (slow). Run songs:scan-audio first.',
                $absJsonl,
            ));
        } else {
            $io->note(sprintf('Loaded %d file records from %s', count($scan), $absJsonl));
        }
        $missingFromScan = 0;

        $finder = (new Finder())
            ->files()
            ->in($absDir)
            ->depth('== 1')
            ->name(array_map(fn (string $ext) => '*.' . $ext, self::AUDIO_EXTENSIONS))
            ->sortByName();

        $total = iterator_count($finder->getIterator());
        if ($total === 0) {
            $io->warning('No audio files found in song directories');
            return Command::SUCCESS;
        }

        $mimeTypes = new MimeTypes();
        $created = 0;
        $skipped = 0;
        $orphaned = 0;
        $count = 0;
        $rows = [];

        foreach ($finder as $file) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }
            $count++;

            $songSlug = basename($file->getPath());
            $song = $this->findSongForDir($file->getPath(), $songSlug, $songCache);
            if ($song === null) {
                $orphaned++;
                if ($io->isVerbose()) {
                    $rows[] = ['orphan', $songSlug, $file->getFilename(), '-'];
                }
                continue;
            }

            $realPath = $file->isLink() ? (string) readlink($file->getPathname()) : $file->getRealPath();
            if (!$realPath || $realPath === '') {
                $realPath = $file->getPathname();
            }

            // Prefer the scan record over stat()ing the symlink target on the mount.
            $scanned = $scan[$realPath] ?? null;
            if ($scanned === null) {
                $missingFromScan++;
            }

            $isReadable = $scanned !== null
                ? (bool) ($scanned['is_readable'] ?? true)
                : is_readable($file->getPathname());

            if (!$isReadable) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->warning(sprintf('Dangling symlink or unreadable: %s', $file->getPathname()));
                }
                continue;
            }

            if (isset($existingPaths[$realPath])) {
                $skipped++;
                continue;
            }

            $ext = strtolower($file->getExtension());
            // Extension lookup, not guessMimeType() — the latter runs finfo, which
            // reads the file's opening bytes over FUSE for every single file.
            $mime = $scanned['mime_type'] ?? ($mimeTypes->getMimeTypes($ext)[0] ?? null);
            $size = (int) ($scanned['size'] ?? $file->getSize());
            $mtime = (int) ($scanned['modified_time'] ?? $file->getMTime());
            $originalFilename = basename($realPath);
            $originalStem = pathinfo($originalFilename, PATHINFO_FILENAME);
            $variant = self::detectVariant($originalStem);
            $title = $this->titleFromFilename($originalStem);

            $audioMeta = $this->loadAudioMeta($file->getPath(), $songSlug);
            $fileMeta = $audioMeta[$file->getFilename()] ?? [];
            // Both are probe-derived, so both are null unless the scan ran with --probe=2.
            $duration = $fileMeta['duration'] ?? $scanned['media_duration'] ?? null;
            $duration = $duration !== null ? (float) $duration : null;

            $fileAsset = new FileAsset(
                path: $realPath,
                relativePath: $songSlug . '/' . $file->getFilename(),
                filename: $fileMeta['originalFilename'] ?? $originalFilename,
                extension: $ext,
                dirname: dirname($realPath),
                size: $size,
                modifiedTime: $mtime,
                isReadable: $isReadable,
                type: 'audio',
                school: $song->school,
                year: $song->year,
                mimeType: $fileMeta['mimeType'] ?? $mime,
                duration: $duration,
            );

            $audio = new Audio(
                fileAsset: $fileAsset,
                song: $song,
                title: $title ?: (string) $song->title,
                format: $ext,
                size: $size,
                variant: $variant,
            );

            if (!$dry) {
                $this->entityManager->persist($fileAsset);
                $this->entityManager->persist($audio);
            }

            $created++;
            $existingPaths[$realPath] = true;
            $rows[] = ['new', $song->title, $file->getFilename(), $variant ?? '-'];

            if ($created % 50 === 0 && !$dry) {
                $this->entityManager->flush();
            }
        }

        if (!$dry) {
            $this->entityManager->flush();
        }

        if ($rows !== []) {
            $io->table(['action', 'song', 'file', 'variant'], $rows);
        }

        $io->success(sprintf(
            '%s — created: %d, skipped (existing): %d, orphaned (no song): %d (of %d found)',
            $dry ? 'DRY RUN' : 'Done',
            $created,
            $skipped,
            $orphaned,
            $total,
        ));

        if ($missingFromScan > 0) {
            $io->warning(sprintf(
                '%d file(s) had no record in the scan and were stat()ed over FUSE. Re-run songs:scan-audio --rescan-audio to refresh it.',
                $missingFromScan,
            ));
        }

        return Command::SUCCESS;
    }

    /** @return array<string, true> */
    private function loadExistingPaths(): array
    {
        $paths = [];
        $result = $this->entityManager->createQuery('SELECT fa.path FROM App\Entity\FileAsset fa WHERE fa.type = :type')
            ->setParameter('type', 'audio')
            ->getScalarResult();
        foreach ($result as $row) {
            $paths[$row['path']] = true;
        }
        return $paths;
    }

    /** @param array<string, Song|null> $cache */
    private function findSongForDir(string $dirPath, string $slug, array &$cache): ?Song
    {
        if (array_key_exists($slug, $cache)) {
            return $cache[$slug];
        }

        $choFiles = glob($dirPath . '/*-lyrics.cho');
        if ($choFiles !== false && $choFiles !== []) {
            $content = file_get_contents($choFiles[0]);
            if ($content !== false && preg_match('/\{title:\s*(.+?)\}/', $content, $m)) {
                $song = $this->songMatcher->find(trim($m[1]));
                $cache[$slug] = $song;
                return $song;
            }
        }

        $song = $this->songMatcher->find(str_replace('-', ' ', $slug));
        $cache[$slug] = $song;
        return $song;
    }

    private function titleFromFilename(string $stem): string
    {
        $base = (string) preg_replace('/^\d+[\.\-\s]+/', '', $stem);
        $base = str_replace(['_', '-'], ' ', $base);
        $base = (string) preg_replace('/\s{2,}/', ' ', $base);
        return trim($base);
    }

    private static function detectVariant(string $filename): ?string
    {
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        $tags = [];

        if (preg_match('/\b(?:gv|guide[_\s-]?vocal|guide[_\s-]?vox)\b/', $name)) {
            $tags[] = 'gv';
        }
        if (preg_match('/\b(?:gtr(?:\s*only)?|guitar(?:\s*only)?)\b/', $name)) {
            $tags[] = 'gtr';
        }
        if (preg_match('/\bwf(?:ly)?\b/', $name)) {
            $tags[] = 'wf';
        }
        if (preg_match('/\b(?:inst(?:rumental)?)\b/', $name)) {
            $tags[] = 'instrumental';
        }
        if (preg_match('/\bdemo\b/', $name)) {
            $tags[] = 'demo';
        }
        if (preg_match('/\b(?:concert|live)\b/', $name)) {
            $tags[] = 'live';
        }
        if (preg_match('/\bbacking[_\s-]?track\b/', $name)) {
            $tags[] = 'backing';
        }

        return $tags !== [] ? implode(',', $tags) : null;
    }

    /**
     * Load an import:dir scan into a map of absolute source path → file facts, so
     * ingest can read size/mtime/mime/duration without stat()ing the FUSE mount.
     *
     * Merges file_info and metadata: file_info always carries the stat fields, while
     * mime_type and media_duration only appear in metadata at probe level >= 1 / 2.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadScan(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $map = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($row) || ($row['type'] ?? null) !== 'FILE') {
                continue;
            }

            $fileInfo = is_array($row['file_info'] ?? null) ? $row['file_info'] : [];
            $pathname = $fileInfo['pathname'] ?? null;
            if (!is_string($pathname) || $pathname === '') {
                continue;
            }

            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $map[$pathname] = $metadata + $fileInfo;
        }

        fclose($handle);

        return $map;
    }

    /** @var array<string, array<string, mixed>> */
    private array $audioMetaCache = [];

    /** @return array<string, array<string, mixed>> */
    private function loadAudioMeta(string $songDir, string $slug): array
    {
        if (isset($this->audioMetaCache[$slug])) {
            return $this->audioMetaCache[$slug];
        }

        $path = $songDir . '/' . $slug . '-audio-meta.json';
        if (!is_file($path)) {
            $this->audioMetaCache[$slug] = [];
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $data = [];
        }

        $this->audioMetaCache[$slug] = is_array($data) ? $data : [];
        return $this->audioMetaCache[$slug];
    }

    private function resetRecords(SymfonyStyle $io): void
    {
        $audioCount = $this->entityManager->createQuery('DELETE FROM App\Entity\Audio')->execute();
        $faCount = $this->entityManager->createQuery('DELETE FROM App\Entity\FileAsset fa WHERE fa.type = :type')
            ->setParameter('type', 'audio')
            ->execute();
        $io->note(sprintf('Reset: deleted %d Audio, %d FileAsset records', $audioCount, $faCount));
    }
}
