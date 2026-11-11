<?php

declare(strict_types=1);

namespace App\Tests;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportMasterSongListTest extends KernelTestCase
{
    public function testHeadersDetermineColumnsAndYearOnlyDatesArePreserved(): void
    {
        $kernel = self::bootKernel();
        $source = tempnam(sys_get_temp_dir(), 'kpa-master-');
        $output = tempnam(sys_get_temp_dir(), 'kpa-csv-');
        try {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet()->setTitle('KPA Songs');
            $sheet->fromArray([
                ['Instrumentals', 'program', 'publisher', 'date', 'school', 'notes'],
                ['Same Title', 'Residency', 'Publisher A', 2026, 'School A', 'A note'],
                ['Same Title', 'Workshop', 'Publisher B', 46023, 'School B', 'B note'],
            ]);
            (new Xlsx($book))->save($source);
            $tester = new CommandTester((new Application($kernel))->find('app:export-master-songlist-csv'));
            $tester->execute(['source' => $source, '--output' => $output]);
            $tester->assertCommandIsSuccessful();
            $f = fopen($output, 'r');
            $header = fgetcsv($f, escape: '');
            $a = array_combine($header, fgetcsv($f, escape: ''));
            $b = array_combine($header, fgetcsv($f, escape: ''));
            fclose($f);
            self::assertSame('Publisher A', $a['publisher']);
            self::assertSame('Residency', $a['program']);
            self::assertSame('', $a['date']);
            self::assertSame('2026', $a['year']);
            self::assertSame('A note', $a['notes']);
            self::assertSame('School B', $b['school']);
            self::assertSame('2026-01-01', $b['date']);
            self::assertSame('2026', $b['year']);
            self::assertSame('3', $b['sourceRow']);
            self::assertSame('KPA Songs', $b['sourceSheet']);
        } finally {
            unlink($source);
            unlink($output);
        }
    }
}
