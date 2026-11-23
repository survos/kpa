<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Moves the song vectors between installations as a portable .jsonl.gz: vectors are generated
 * on a workstation (app:songs:embed, Ollama and the DuckDB cache), pushed to the shared bucket,
 * and imported where neither is available, e.g. production, which then only reindexes.
 *
 * Line 1 is a header with the embedding profile; then one {id, fingerprint, embedding} per song.
 * A vector is only imported where this installation's own song text gives the same fingerprint.
 */
final readonly class SongVectorArchive
{
    public const string REMOTE_KEY = 'survos-sites/kpa/song-vectors.jsonl.gz';
    private const int SCHEMA_VERSION = 1;

    public function __construct(
        private Connection $db,
        private SongEmbeddingService $embeddings,
        #[Autowire(service: 'kpa.embeddings.storage')] private FilesystemOperator $remote,
        #[Autowire('%kernel.project_dir%/var/embeddings/song-vectors.jsonl.gz')] private string $localPath,
    ) {
    }

    #[AsCommand('app:songs:vectors:export', 'Write the song vectors to a portable .jsonl.gz, and optionally push it to the shared bucket')]
    public function export(
        SymfonyStyle $io,
        #[Argument('Where to write the archive')] ?string $path = null,
        #[Option('Upload it to the shared bucket ('.self::REMOTE_KEY.')')] bool $push = false,
    ): int {
        $path ??= $this->localPath;
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0775, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException(sprintf('Cannot create "%s".', \dirname($path)));
        }
        $out = gzopen($path, 'wb6') ?: throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
        gzwrite($out, json_encode(['schemaVersion' => self::SCHEMA_VERSION, 'profile' => $this->profile(), 'exportedAt' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR)."\n");
        $count = 0;
        foreach ($this->db->iterateAssociative('SELECT id, embedding, embedding_fingerprint FROM song WHERE embedding IS NOT NULL ORDER BY id') as $row) {
            gzwrite($out, json_encode(['id' => $row['id'], 'fingerprint' => $row['embedding_fingerprint'], 'embedding' => json_decode((string) $row['embedding'], true, flags: JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)."\n");
            ++$count;
        }
        gzclose($out);
        $io->writeln(sprintf('Wrote %d song vectors to %s (%s).', $count, $path, self::size((int) filesize($path))));

        if ($push) {
            $in = fopen($path, 'rb') ?: throw new \RuntimeException(sprintf('Cannot read "%s".', $path));
            try {
                $this->remote->writeStream(self::REMOTE_KEY, $in, ['visibility' => 'private']);
            } finally {
                fclose($in);
            }
            $io->writeln(sprintf('Pushed to s3://…/%s.', self::REMOTE_KEY));
        }
        $io->success(sprintf('Exported %d song vectors.', $count));

        return Command::SUCCESS;
    }

    #[AsCommand('app:songs:vectors:import', 'Import song vectors from the shared bucket or a .jsonl.gz file; only where the song text is unchanged')]
    public function import(
        SymfonyStyle $io,
        #[Argument('A local .jsonl.gz; the shared bucket when omitted')] ?string $path = null,
        #[Option('Count what would change, write nothing')] bool $dryRun = false,
    ): int {
        $source = $path ?? $this->download();
        try {
            $stats = $this->apply($source, $dryRun);
        } finally {
            if (null === $path) {
                @unlink($source);
            }
        }
        $io->writeln(json_encode($stats + ['dryRun' => $dryRun], JSON_THROW_ON_ERROR));
        if ($stats['written'] > 0) {
            $io->note('Rebuild the search index to serve them: bin/console elastic:index:rebuild app_song --keep-old');
        }
        $io->success(sprintf('%d song vectors %s; %d unchanged, %d skipped (song text differs), %d unknown songs.', $dryRun ? $stats['wouldWrite'] : $stats['written'], $dryRun ? 'would be written' : 'written', $stats['unchanged'], $stats['textDiffers'], $stats['unknownSong']));

        return Command::SUCCESS;
    }

    /**
     * @return array{written: int, wouldWrite: int, unchanged: int, textDiffers: int, unknownSong: int}
     */
    public function apply(string $path, bool $dryRun = false): array
    {
        $in = gzopen($path, 'rb') ?: throw new \RuntimeException(sprintf('Cannot read "%s".', $path));
        try {
            $header = json_decode((string) gzgets($in), true, flags: JSON_THROW_ON_ERROR);
            if (self::SCHEMA_VERSION !== ($header['schemaVersion'] ?? null) || $header['profile'] !== $this->profile()) {
                throw new \UnexpectedValueException(sprintf('These vectors were made with another embedding profile (%s), this app uses %s.', json_encode($header['profile'] ?? null), json_encode($this->profile())));
            }

            $songs = [];
            foreach ($this->db->iterateAssociative('SELECT id, title, description, school, year, writers, lyrics, embedding_fingerprint FROM song') as $row) {
                $songs[$row['id']] = $row;
            }

            $stats = ['written' => 0, 'wouldWrite' => 0, 'unchanged' => 0, 'textDiffers' => 0, 'unknownSong' => 0];
            while (false !== $line = gzgets($in)) {
                if ('' === trim($line)) {
                    continue;
                }
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                $song = $songs[$row['id']] ?? null;
                if (null === $song) {
                    ++$stats['unknownSong'];
                } elseif ($row['fingerprint'] === $song['embedding_fingerprint']) {
                    ++$stats['unchanged'];
                } elseif ($row['fingerprint'] !== $this->fingerprint($song) || !SongEmbeddingService::validVector($row['embedding'])) {
                    ++$stats['textDiffers'];
                } elseif ($dryRun) {
                    ++$stats['wouldWrite'];
                } else {
                    $this->db->update('song', ['embedding' => json_encode($row['embedding'], JSON_THROW_ON_ERROR), 'embedding_fingerprint' => $row['fingerprint']], ['id' => $row['id']]);
                    ++$stats['written'];
                }
            }

            return $stats;
        } finally {
            gzclose($in);
        }
    }

    /** @return array<string, int|string> */
    private function profile(): array
    {
        $p = $this->embeddings->profile;

        return ['model' => $p->model, 'revision' => $p->revision, 'dimensions' => $p->dimensions, 'textVersion' => $p->textVersion];
    }

    /** @param array<string, mixed> $row */
    private function fingerprint(array $row): string
    {
        $song = new \App\Entity\Song((string) $row['id']);
        foreach (['title', 'description', 'school', 'writers', 'lyrics'] as $field) {
            $song->{$field} = $row[$field];
        }
        $song->year = null === $row['year'] ? null : (int) $row['year'];

        return $this->embeddings->profile->fingerprint(SongEmbeddingService::text($song));
    }

    private function download(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kpa-vectors-') ?: throw new \RuntimeException('Cannot create a temporary file.');
        $out = fopen($path, 'wb') ?: throw new \RuntimeException(sprintf('Cannot write "%s".', $path));
        try {
            stream_copy_to_stream($this->remote->readStream(self::REMOTE_KEY), $out);
        } finally {
            fclose($out);
        }

        return $path;
    }

    private static function size(int $bytes): string
    {
        return $bytes > 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%d KB', intdiv($bytes, 1024));
    }
}
