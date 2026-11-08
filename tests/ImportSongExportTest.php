<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportSongExportTest extends KernelTestCase
{
    public function testRecoveryPreservesIdsLyricsAndExistingValues(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($em->getConnection()->getParams()['memory'] ?? false);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $path = tempnam(sys_get_temp_dir(), 'kpa-export-');
        try {
            file_put_contents($path, "id,title,lyrics,year,date,lyricsLength,chorus,fileCount\noriginal-id,Example,Actual lyrics,2025,2025-04-01T00:00:00+00:00,999,derived,7\n");
            $tester = new CommandTester((new Application($kernel))->find('app:import-song-export'));
            $tester->execute(['source' => $path, '--dry-run' => true]);
            $tester->assertCommandIsSuccessful();
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT count(*) FROM song'));
            $tester->execute(['source' => $path]);
            $tester->assertCommandIsSuccessful();
            file_put_contents($path, "id,title,lyrics\noriginal-id,Updated title,\n");
            $tester->execute(['source' => $path]);
            $tester->assertCommandIsSuccessful();
            $row = $em->getConnection()->fetchAssociative('SELECT * FROM song');
            self::assertSame('original-id', $row['id']);
            self::assertSame('Updated title', $row['title']);
            self::assertSame('Actual lyrics', $row['lyrics']);
            self::assertSame(mb_strlen('Actual lyrics'), $row['lyrics_length']);
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT count(*) FROM song'));
        } finally {
            unlink($path);
        }
    }
}
