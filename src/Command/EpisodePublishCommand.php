<?php

declare(strict_types=1);

namespace App\Command;

use App\Episode\EpisodeProducer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

final class EpisodePublishCommand
{
    public function __construct(private readonly EpisodeProducer $producer)
    {
    }

    #[AsCommand('app:episode:publish', 'Voice a written episode, cut its song excerpts, and publish it as one MP3 with chapters and a transcript')]
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The script JSON written by app:episode:write')] string $script,
        #[Argument('The brief JSON it was written from (songs, recordings, excerpt times)')] string $brief,
        #[Argument('URL name of the episode, e.g. holidays')] string $slug,
    ): int {
        $read = static fn (string $path) => json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $episode = $this->producer->produce($slug, $read($script), $read($brief), $io->writeln(...));

        $io->definitionList(
            ['Title' => $episode['title']],
            ['Length' => gmdate('i:s', (int) $episode['seconds'])],
            ['Chapters' => implode(', ', array_map(static fn (array $c) => gmdate('i:s', (int) $c['start']).' '.$c['title'], $episode['chapters']))],
        );
        $io->success(sprintf('Published "%s" (%s bytes).', $episode['slug'], number_format($episode['bytes'])));

        return Command::SUCCESS;
    }
}
