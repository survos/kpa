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
    public function testReviewedSourceOverridesLegacyFolderAndPreservesRecordingYear(): void
    {
        $song = new Song('irish-um'); $song->title = 'Um'; $song->year = 2019;
        $repo = $this->createStub(SongRepository::class); $repo->method('findAll')->willReturn([$song]);
        $db = $this->createStub(Connection::class); $db->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo); $em->method('getConnection')->willReturn($db);
        $saved = [];
        $em->expects(self::exactly(2))->method('persist')->willReturnCallback(static function ($entity) use (&$saved): void { $saved[] = $entity; });
        $em->expects(self::once())->method('flush');
        $manifest = tempnam(sys_get_temp_dir(), 'kpa-sidecar-');
        $review = tempnam(sys_get_temp_dir(), 'kpa-review-');
        try {
            file_put_contents($manifest, json_encode([['archive'=>['objectKey'=>'duh/duh-gv.mp3'], 'filename'=>'Um gv.mp3', 'probedData'=>['format'=>['tags'=>['date'=>'2020']]]]]));
            file_put_contents($review, json_encode(['duh/duh-gv.mp3'=>['songId'=>'irish-um', 'expectedTitle'=>'Um', 'sourceFilename'=>'Um gv.mp3', 'recordingSchool'=>'Irish', 'reason'=>'Original filename and school agree.']]));
            $report = (new ArchivedAudioImporter($em, new SongMatcher($repo, $em)))->import($manifest, false, 0, $review);
            self::assertSame(1, $report['created']);
            self::assertSame('https://kpa-songs.s3.amazonaws.com/duh/duh-gv.mp3', $saved[0]->s3);
            self::assertSame(2020, $saved[0]->year);
            self::assertSame(2019, $song->year);
            self::assertSame('gv', $saved[1]->variant);
            self::assertSame($song, $saved[1]->song);
            $song->title = 'A different title';
            $this->expectException(\InvalidArgumentException::class);
            (new ArchivedAudioImporter($em, new SongMatcher($repo, $em)))->import($manifest, true, 0, $review);
        } finally { unlink($manifest); unlink($review); }
    }

}
