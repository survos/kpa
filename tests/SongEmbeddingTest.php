<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Song;
use App\Search\SongSearch;
use App\Service\SongEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SongEmbeddingTest extends KernelTestCase
{
    public function testCachedVectorsInvalidateOnContentAndModelChangesWithoutCallingOllama(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects(self::never())->method('invoke');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new SongEmbeddingService($platform, $http, $em, 'nomic-embed-text:latest', 'revision1');
        $song = new Song('original-id');
        $song->title = 'Friendship';
        $song->lyrics = '{title: Friendship}\n[C]We stand together';
        $song->embedding = array_fill(0, 768, 0.1);
        $song->embeddingFingerprint = $service->profile->fingerprint($service::text($song));
        self::assertSame($song->embedding, $service->cachedVector($song));
        $otherRevision = new SongEmbeddingService($platform, $http, $em, 'nomic-embed-text:latest', 'revision2');
        self::assertNull($otherRevision->cachedVector($song));
        $song->lyrics = 'Changed lyrics';
        self::assertNull($service->cachedVector($song));
        $song->lyrics = str_repeat('draft ', 1001);
        self::assertTrue($service::needsLyricsReview($song));
        self::assertNull($service->cachedVector($song));
        self::assertFalse($service::validVector([0.1]));
        self::assertFalse($service::validVector(array_fill(0, 768, NAN)));
        self::assertFalse($service::validVector(array_fill(0, 768, 0.0)));
    }

    public function testGenericFieldsDriveSearchAndStaleVectorsAreExplicitlyCleared(): void
    {
        self::bootKernel();
        $search = self::getContainer()->get(SearchProvider::class)->getSearch('app_song')->create();
        self::assertInstanceOf(SongSearch::class, $search);
        $parameters = $search->getAdapterParameters();
        self::assertContains('title', $parameters['searchFields']);
        self::assertContains('lyrics', $parameters['searchFields']);
        self::assertArrayHasKey('school', $parameters['facetFields']);
        self::assertSame('dense_vector', $parameters['mappings']['embedding']['type']);
        self::assertNotContains('embedding', $parameters['sourceFields']);
        $song = new Song('test');
        $song->title = 'Example';
        $song->lyrics = 'Lyrics';
        $song->embedding = array_fill(0, 768, 0.1);
        $song->embeddingFingerprint = 'stale';
        $document = ($parameters['documentMapper'])($song);
        self::assertNull($document['embedding']);
        self::assertSame('Example', $document['title']);
        self::assertSame(0, $document['fileCount']);
        self::assertSame('integer', $parameters['mappings']['fileCount']['type']);
        self::assertSame('fileCount', $parameters['facetFields']['fileCount']);
        self::assertSame('fileCount', $parameters['sortFields']['fileCount']);
        $song->audios->add($this->createStub(\App\Entity\Audio::class));
        $song->audios->add($this->createStub(\App\Entity\Audio::class));
        self::assertSame(2, ($parameters['documentMapper'])($song)['fileCount']);
        self::assertArrayNotHasKey('embeddingFingerprint', $document);
    }
}
