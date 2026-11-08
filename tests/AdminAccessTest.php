<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessTest extends WebTestCase
{
    public function testAnonymousVisitorsMustLogIn(): void
    {
        $client = self::createClient();
        foreach (['/admin', '/admin/song', '/admin/file-asset', '/admin/song/new'] as $path) {
            $client->request('GET', $path);
            self::assertResponseRedirects('/login');
        }
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="password"]');
    }

    public function testRegularUsersCannotAccessAdmin(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(User::class)]);
        $user = (new User())->setEmail('reader@example.test')->setPassword('unused');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/admin/song');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanBrowseBothTables(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $user = (new User())->setEmail('admin@example.test')->setPassword('unused')->setRoles(['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/song');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/file-asset');
        self::assertResponseIsSuccessful();
    }
}
