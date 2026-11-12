<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SongSearchPageTest extends WebTestCase
{
    public function testLegacySearchRedirectsToBundleWithoutRunningItsOwnSearch(): void
    {
        $client = self::createClient();
        $client->request('GET', '/songs/search?q=friendship&mode=compare');
        self::assertResponseRedirects('/search?q=friendship');
    }
}
