<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Audio;
use App\Episode\SongCardFactory;
use App\Episode\ThemeFinder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:episode:songs', 'List the songs that fit a theme, by meaning, with their recordings')]
final readonly class EpisodeSongsCommand
{
    public function __construct(private ThemeFinder $finder)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The theme, e.g. "winter holidays"')] string $theme,
        #[Option('How many songs to list')] int $limit = 12,
    ): int {
        foreach ($this->finder->find($theme, $limit) as ['song' => $song, 'score' => $score]) {
            $io->writeln(sprintf('<info>%.3f</info> <comment>%s</comment> %s (%s, %s)', $score, $song->id, $song->title, $song->school, $song->year));
            foreach ($song->audios as $audio) {
                /** @var Audio $audio */
                $io->writeln(sprintf('        %s, %s  %s', SongCardFactory::performance($audio), $audio->fileAsset->duration ? gmdate('i:s', (int) $audio->fileAsset->duration) : '?', $audio->fileAsset->s3 ?? '(not on S3)'));
            }
        }

        return Command::SUCCESS;
    }
}
