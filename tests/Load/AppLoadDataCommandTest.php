<?php

namespace App\Tests\Load;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Console\Test\InteractsWithConsole;

class AppLoadDataCommandTest extends KernelTestCase
{
    use InteractsWithConsole;

    protected function setUp(): void
    {
        parent::setUp();

        static::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = array_values(array_filter(
            $entityManager->getMetadataFactory()->getAllMetadata(),
            static fn($meta) => str_starts_with($meta->getName(), 'App\\Entity\\')
        ));

        (new SchemaTool($entityManager))->createSchema($metadata);
    }

    #[Test]
    public function load(): void
    {
        $this->consoleCommand('app:load')
            ->addOption('songs')
            ->addOption('limit', 3)
            ->execute()
            ->assertSuccessful() // command exit code is 0
            ->assertOutputContains('Songs Loaded ')
            ->assertOutputContains(' songs')
            ->assertOutputNotContains('failed')
        ;
    }

}
