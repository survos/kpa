<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Song;
use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Search\ResultSet\Hit;
use Survos\SearchBundle\Search\ResultSet\ResultSet;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SongSearchPageTest extends WebTestCase
{
    public function testComparisonUsesThreeModesAndRendersHydratedSongs(): void
    {
        $client = self::createClient();
        $song = new Song('test-song');
        $song->title = 'My Shadow';
        $song->lyrics = '{title: My Shadow} [C]Follow me &amp; sing';
        $modes = [];
        $searcher = $this->createMock(Searcher::class);
        $searcher->expects(self::exactly(3))->method('search')->willReturnCallback(
            static function (Query $query, SearchInterface $search) use (&$modes, $song): ResultSet {
                $modes[] = $search->getAdapterParameters()['retrievalMode'];
                self::assertSame('shadow', $query->getQueryString());
                self::assertNull($query->getActiveSort());
                return (new ResultSet())->setHits([new Hit($song, 1.0)]);
            }
        );
        self::getContainer()->set(Searcher::class, $searcher);
        $client->request('GET', '/songs/search?q=shadow&mode=compare');
        self::assertResponseIsSuccessful();
        self::assertSame(['lexical', 'vector', 'hybrid'], $modes);
        self::assertSelectorCount(3, 'article');
        self::assertSelectorTextContains('article', 'Score: 1.000000');
        self::assertSelectorTextContains('article', 'Follow me & sing');
        self::assertSelectorTextNotContains('article', '{title:');
    }
}
