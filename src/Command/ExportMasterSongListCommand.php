<?php

declare(strict_types=1);

namespace App\Command;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:export-master-songlist-csv', 'Convert the KPA "Master Song List" .xlsx (KPA Songs sheet) to a clean CSV with normalised headers')]
final class ExportMasterSongListCommand
{
    /** Source headers, not column positions: the archive inserts columns over time. */
    private const HEADER_MAP = [
        'instrumentals' => 'title', 'title' => 'title', 'writer' => 'writer',
        'school' => 'school', 'co-writer' => 'co_writer', 'date' => 'date',
        'co-writer/assistant' => 'co_writer_assistant', 'record status' => 'record_status',
        'recording' => 'recording', 'program' => 'program', 'publisher' => 'publisher',
        'iswc' => 'iswc', 'isrc' => 'isrc', 'tuning' => 'tuning', 'ascap' => 'ascap',
        '©' => 'copyright', 'midi' => 'midi', 's/i/k' => 'category',
        'year' => 'year', 'assist' => 'assist', 'notes' => 'notes',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('path to the master song list .xlsx')]
        string $source,
        #[Option('output CSV path')]
        string $output = 'data/master-song-list.csv',
        #[Option('worksheet name')]
        string $sheet = 'KPA Songs',
    ): int {
        if (!is_file($source) || !is_readable($source)) {
            $io->error(sprintf('Source not readable: %s', $source));
            return Command::FAILURE;
        }

        $absOutput = str_starts_with($output, '/') ? $output : $this->projectDir . '/' . ltrim($output, '/');
        $this->filesystem->mkdir(dirname($absOutput));

        $reader = IOFactory::createReaderForFile($source);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheet]);
        $worksheet = $reader->load($source)->getSheetByName($sheet);
        if ($worksheet === null) {
            $io->error(sprintf('Worksheet "%s" not found.', $sheet));
            return Command::FAILURE;
        }

        $lastRow = $worksheet->getHighestDataRow();
        $range = sprintf('A1:%s%d', $worksheet->getHighestDataColumn(), $lastRow);
        $rows = $worksheet->rangeToArray($range, null, false, false);

        $columns = [];
        foreach ($rows[0] as $index => $header) {
            $key = mb_strtolower(trim((string) $header));
            if (isset(self::HEADER_MAP[$key])) {
                $columns[self::HEADER_MAP[$key]] = $index;
            }
        }
        if (!isset($columns['title'])) {
            $io->error('No recognized song title header. Refusing a positional import.');
            return Command::FAILURE;
        }
        // Keep year available even when a source only supplies year in its date column.
        $columns['year'] ??= null;
        $written = 0;
        $skipped = 0;
        $fp = fopen($absOutput, 'w');
        if ($fp === false) {
            $io->error(sprintf('Cannot open output for writing: %s', $absOutput));
            return Command::FAILURE;
        }

        fputcsv($fp, [...array_keys($columns), 'sourceSheet', 'sourceRow'], ',', '"', '');

        foreach ($rows as $idx => $row) {
            // Row 1 is the source header; we replace it with our clean headers above.
            if ($idx === 0) {
                continue;
            }

            $title = trim((string) ($row[$columns['title']] ?? ''));
            if ($title === '') {
                $skipped++;
                continue;
            }

            $out = [];
            foreach ($columns as $field => $column) {
                $cell = $column === null ? null : ($row[$column] ?? null);
                $out[$field] = $this->normaliseCell($field, $cell);
            }
            $rawDate = isset($columns['date']) ? trim((string) ($row[$columns['date']] ?? '')) : '';
            if (preg_match('/^(19|20)\d{2}$/D', $rawDate)) {
                $out['date'] = '';
                $out['year'] = $out['year'] !== '' ? $out['year'] : $rawDate;
            } elseif ($out['year'] === '' && preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $out['date'] ?? '', $match)) {
                $out['year'] = $match[1];
            }
            fputcsv($fp, [...array_values($out), $sheet, $idx + 1], ',', '"', '');
            $written++;
        }
        fclose($fp);

        $io->success(sprintf(
            '%d rows written to %s (%d empty/skipped)',
            $written,
            str_replace($this->projectDir . '/', '', $absOutput),
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function normaliseCell(string $field, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($field === 'date' && is_numeric($value) && (float) $value > 10000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
