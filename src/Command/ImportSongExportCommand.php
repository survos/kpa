<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Song;
use App\Workflow\SongWFDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ImportSongExportCommand
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[AsCommand('app:import-song-export', 'Recover songs from the KPA CSV export, preserving IDs and existing nonempty values')]
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('CSV exported from KPA')] string $source,
        #[Option('Validate and report without writing')] bool $dryRun = false,
    ): int {
        $file = new \SplFileObject($source, 'r');
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv();
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        foreach (['id', 'title'] as $required) {
            if (!in_array($required, $header, true)) {
                throw new \InvalidArgumentException('Missing CSV column: '.$required);
            }
        }
        $metadata = $this->em->getClassMetadata(Song::class);
        $rows = [];
        $withLyrics = 0;
        while (!$file->eof()) {
            $values = $file->fgetcsv();
            if ($values === false || $values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                throw new \InvalidArgumentException('CSV row has a different number of columns than the header.');
            }
            $row = array_combine($header, $values);
            $id = trim($row['id']);
            if ($id === '' || trim($row['title']) === '' || isset($rows[$id])) {
                throw new \InvalidArgumentException('Missing ID/title or duplicate ID: '.$id);
            }
            $data = [];
            foreach (['id', 'title', 'description', 'school', 'lyrics', 'featuredArtist', 'recordingCredits', 'musicians', 'writers', 'recording', 'publisher', 'notes'] as $field) {
                // Empty cells never erase information already recovered from another source.
                if (isset($row[$field]) && trim($row[$field]) !== '') {
                    $data[$metadata->getColumnName($field)] = $row[$field];
                }
            }
            if (trim($row['year'] ?? '') !== '') {
                if (!preg_match('/^-?\d+$/D', $row['year'])) {
                    throw new \InvalidArgumentException('Invalid year for '.$id);
                }
                $data[$metadata->getColumnName('year')] = (int) $row['year'];
            }
            if (trim($row['date'] ?? '') !== '') {
                $data[$metadata->getColumnName('date')] = (new \DateTimeImmutable($row['date']))->format('Y-m-d');
            }
            if (isset($data[$metadata->getColumnName('lyrics')])) {
                $data[$metadata->getColumnName('lyricsLength')] = mb_strlen($row['lyrics']);
                ++$withLyrics;
            }
            $rows[$id] = $data;
        }
        if ($dryRun) {
            $io->success(sprintf('Validated %d songs; %d contain lyrics. No writes.', count($rows), $withLyrics));
            return Command::SUCCESS;
        }

        // Recovery writes deliberately bypass ORM listeners: do not enqueue legacy Meili
        // indexing or workflows. Rebuild the SearchBundle ES projection after the import.
        $db = $this->em->getConnection();
        $created = $updated = 0;
        $db->transactional(function () use ($db, $metadata, $rows, &$created, &$updated): void {
            $table = $metadata->getTableName();
            foreach ($rows as $id => $data) {
                if ($db->fetchOne('SELECT 1 FROM '.$table.' WHERE id = ?', [$id])) {
                    $db->update($table, $data, ['id' => $id]);
                    ++$updated;
                } else {
                    $data[$metadata->getColumnName('marking')] = SongWFDefinition::PLACE_NEW;
                    $db->insert($table, $data);
                    ++$created;
                }
            }
        });
        $io->success(sprintf('Created %d songs; updated %d; source contains %d lyrics.', $created, $updated, $withLyrics));
        return Command::SUCCESS;
    }
}
