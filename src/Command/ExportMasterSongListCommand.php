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
    /**
     * Source column letters (1-indexed letters from the .xlsx) → clean snake_case CSV headers.
     * Trailing empty columns (T..X) are dropped.
     */
    private const COLUMN_MAP = [
        'A' => 'title',          // header in xlsx is misleadingly "Instrumentals" — actual data is the song title
        'B' => 'writer',
        'C' => 'school',
        'D' => 'co_writer',
        'E' => 'date',           // Excel serial → ISO YYYY-MM-DD
        'F' => 'co_writer_assistant',
        'G' => 'record_status',
        'H' => 'recording',
        'I' => 'publisher',
        'J' => 'iswc',
        'K' => 'isrc',
        'L' => 'tuning',
        'M' => 'ascap',
        'N' => 'copyright',
        'O' => 'midi',
        'P' => 'category',       // header in xlsx is "s/I/k"
        'Q' => 'year',
        'R' => 'assist',
        'S' => 'notes',
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
        $columnLetters = array_keys(self::COLUMN_MAP);
        $range = sprintf('%s1:%s%d', $columnLetters[0], end($columnLetters), $lastRow);
        $rows = $worksheet->rangeToArray($range, null, false, false);

        $written = 0;
        $skipped = 0;
        $fp = fopen($absOutput, 'w');
        if ($fp === false) {
            $io->error(sprintf('Cannot open output for writing: %s', $absOutput));
            return Command::FAILURE;
        }

        fputcsv($fp, array_values(self::COLUMN_MAP));

        foreach ($rows as $idx => $row) {
            // Row 1 is the source header; we replace it with our clean headers above.
            if ($idx === 0) {
                continue;
            }

            $title = trim((string) ($row[0] ?? ''));
            if ($title === '') {
                $skipped++;
                continue;
            }

            $out = [];
            $i = 0;
            foreach (self::COLUMN_MAP as $field) {
                $cell = $row[$i] ?? null;
                $out[] = $this->normaliseCell($field, $cell);
                $i++;
            }
            fputcsv($fp, $out);
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

        if ($field === 'date' && is_numeric($value) && (float) $value > 1000) {
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
