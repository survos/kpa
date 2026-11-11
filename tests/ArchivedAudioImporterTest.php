<?php

declare(strict_types=1);
namespace App\Tests;

use App\Entity\Song;
use App\Service\ArchivedAudioImporter;
use App\Service\SongMatcher;
use App\Repository\SongRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ArchivedAudioImporterTest extends TestCase
{
    public function testOnlyUniqueCompatibleTitlesMatchAndExistingUrlsAreSkipped(): void
    {
        $songs = [];
        foreach ([['one','Unique Song',2019], ['two','Same Title',2019], ['three','Same Title',2019], ['four','Wrong Year',2020]] as [$id,$title,$year]) {
            $s = new Song($id); $s->title = $title; $s->year = $year; $songs[] = $s;
        }
        $repo = $this->createStub(SongRepository::class);
        $repo->method('findAll')->willReturn($songs);
        $db = $this->createStub(Connection::class);
        $db->method('fetchAllAssociative')->willReturn([['s3' => 'https://kpa-songs.s3.amazonaws.com/unique-song/already.mp3']]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('getConnection')->willReturn($db);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $rows = [];
        foreach (['unique-song/recording.mp3', 'unique-song/already.mp3', 'same-title/recording.mp3', 'wrong-year/recording.mp3', 'missing/recording.mp3'] as $key) {
            $rows[] = ['archive'=>['objectKey'=>$key], 'probedData'=>['format'=>['tags'=>['date'=>'2019']]]];
        }
        $path = tempnam(sys_get_temp_dir(), 'kpa-audio-test-');
        try {
            file_put_contents($path, json_encode($rows));
            $report = (new ArchivedAudioImporter($em, new SongMatcher($repo, $em)))->import($path);
            self::assertSame(1, $report['created']);
            self::assertSame(1, $report['existing']);
            self::assertSame('one', $report['matches'][0]['songId']);
            self::assertCount(1, $report['ambiguous']);
            self::assertCount(1, $report['yearConflict']);
            self::assertCount(1, $report['unmatched']);
        } finally { unlink($path); }
    }
}
