<?php

declare(strict_types=1);
namespace App\Tests;

use App\Entity\FileAsset;
use App\Entity\Song;
use App\Repository\SongRepository;
use App\Service\ArchivedAudioImporter;
use App\Service\SongMatcher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DropboxPlaybackTest extends TestCase
{
    public function testReviewedPublicLinkCreatesPlayableM4aWithoutMarkingItAsS3(): void
    {
        $song = new Song('example'); $song->title = 'Example'; $song->school = 'School';
        $db = $this->createStub(Connection::class); $db->method('fetchFirstColumn')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($db); $em->method('find')->willReturn($song);
        $saved = []; $em->expects(self::exactly(2))->method('persist')->willReturnCallback(static function ($e) use (&$saved) {$saved[]=$e;});
        $em->expects(self::once())->method('flush');
        $row = ['song'=>['id'=>'example','title'=>'Example','school'=>'School'], 'Name'=>'Example.m4a', 'Path'=>'School/Example.m4a',
            'ID'=>'id:example', 'Size'=>123, 'ModTime'=>'2026-01-01T00:00:00Z', 'evidence'=>'Reviewed title and school',
            'link'=>['url'=>'https://www.dropbox.com/scl/fi/test/Example.m4a?rlkey=key&dl=0', 'audience'=>'public','allow_download'=>true]];
        $file=tempnam(sys_get_temp_dir(),'kpa-dropbox-');
        try {
            file_put_contents($file,json_encode([$row,$row]));
            $repo=$this->createStub(SongRepository::class);
            $report=(new ArchivedAudioImporter($em,new SongMatcher($repo,$em)))->importDropbox($file,false);
            self::assertSame(1,$report['created']); self::assertSame(1,$report['existing']);
            self::assertSame('audio/mp4',$saved[0]->mimeType); self::assertNull($saved[0]->s3);
            self::assertSame('https://www.dropbox.com/scl/fi/test/Example.m4a?rlkey=key&raw=1',$saved[0]->getPlaybackUrl());
        } finally {unlink($file);}
    }
    public function testPrivateLinksAreRejectedBeforeWriting(): void
    {
        $db=$this->createStub(Connection::class); $db->method('fetchFirstColumn')->willReturn([]);
        $em=$this->createMock(EntityManagerInterface::class);$em->method('getConnection')->willReturn($db);
        $em->expects(self::never())->method('persist');$em->expects(self::never())->method('flush');
        $file=tempnam(sys_get_temp_dir(),'kpa-private-');
        try {
            file_put_contents($file,json_encode([['link'=>['url'=>'https://www.dropbox.com/s/test/song.mp3','audience'=>'no_one']]]));
            $this->expectException(\InvalidArgumentException::class);
            (new ArchivedAudioImporter($em,new SongMatcher($this->createStub(SongRepository::class),$em)))->importDropbox($file,false);
        } finally {unlink($file);}
    }
}
