<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Song;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SongFiltersTest extends WebTestCase
{
    #[TestWith(['title=Alpha', ['Alpha']])]
    #[TestWith(['search=Bravo', ['Bravo']])]
    #[TestWith(['order[title]=desc', ['Bravo', 'Alpha']])]
    #[TestWith(['order[title]=asc', ['Alpha', 'Bravo']])]
    #[TestWith(['facet_filter[]=school,eq,North', ['Alpha']])]
    public function testCollectionFilters(string $query, array $titles): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertTrue($em->getConnection()->getParams()['memory'] ?? false);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach (['Alpha' => 'North', 'Bravo' => 'South'] as $title => $school) {
            $song = new Song(strtolower($title));
            $song->title = $title;
            $song->school = $school;
            $em->persist($song);
        }
        $em->flush();
        $url = self::getContainer()->get('router')->generate(Song::DOCTRINE_ROUTE);
        $client->request('GET', $url.'?'.$query, server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($titles, array_column($data['member'], 'title'));
    }
}
