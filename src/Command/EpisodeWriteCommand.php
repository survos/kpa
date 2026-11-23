<?php

declare(strict_types=1);

namespace App\Command;

use App\Episode\Cast;
use App\Episode\Line;
use App\Episode\SongBrief;
use App\Episode\SongCardFactory;
use App\Episode\SongScript;
use App\Episode\SongScriptWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand('app:episode:write', 'Write a themed episode of KPA songs, with its excerpts, as a running order')]
final readonly class EpisodeWriteCommand
{
    public function __construct(
        private SongCardFactory $cards,
        private SongScriptWriter $writer,
        private Cast $cast,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the episode JSON: theme, hosts, and per song its id, recording and excerpt times')] string $brief,
        #[Option('Where to write the running order (Markdown); the script (JSON) goes next to it')] ?string $output = null,
    ): int {
        $brief = $this->brief(json_decode($this->filesystem->readFile($brief), true, flags: \JSON_THROW_ON_ERROR));
        $script = $this->writer->write($brief);
        $markdown = $this->runningOrder($script, $brief);

        if (null === $output) {
            $io->writeln($markdown);
        } else {
            $this->filesystem->dumpFile($output, $markdown);
            $this->filesystem->dumpFile(preg_replace('/\.md$/', '', $output).'.json', (string) json_encode($script, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
            $io->success("Running order written to {$output}");
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{theme: string, hosts: list<string>, minutes?: int, tone?: string, songs: list<array{song: string, recording: string, start: float, end: float, lyrics?: string}>, sources?: list<array{title: string, notes: string}>} $data
     */
    private function brief(array $data): SongBrief
    {
        $songs = array_map(fn (array $s) => $this->cards->card($s['song'], $s['recording'], (float) $s['start'], (float) $s['end'], $s['lyrics'] ?? ''), $data['songs']);

        return new SongBrief($data['theme'], $songs, $data['sources'] ?? [], $data['hosts'], $data['minutes'] ?? 2, $data['tone'] ?? null);
    }

    private function runningOrder(SongScript $script, SongBrief $brief): string
    {
        $line = fn (Line $line) => sprintf("**%s** _(%s)_%s: %s\n\n", $this->cast->hosts[$line->host]->name ?? $line->host, $line->emotion, [] === $line->sources ? '' : ' ['.implode(', ', $line->sources).']', $line->text);

        $md = "# {$script->title}\n\n> {$script->summary}\n\n## Opening\n\n".implode('', array_map($line, $script->opening));
        foreach ($script->segments as $segment) {
            $song = $brief->songs[$segment->song - 1];
            $md .= "## {$segment->song}. {$song->title}\n\n".implode('', array_map($line, $segment->intro));
            $md .= sprintf("> ▶ **EXCERPT** `%s` %s (%.1f s), %s, fade in 0.5 s, out 1.5 s  \n> `%s`%s\n\n", basename($song->recording), $song->excerptAt(), $song->excerptLength(), $song->performance, $song->recording, '' === $song->excerptLyrics ? '' : "  \n> _{$song->excerptLyrics}_");
            $md .= implode('', array_map($line, $segment->background));
        }
        $md .= "## Closing\n\n".implode('', array_map($line, $script->closing))."## Sources\n\n";
        foreach ($brief->songs as $i => $song) {
            $md .= sprintf("%d. %s (KPA song `%s`, %s)\n", $i + 1, $song->title, $song->songId, $song->recording);
        }
        foreach ($brief->sources as $i => $source) {
            $md .= sprintf("%d. %s\n", \count($brief->songs) + $i + 1, $source['title']);
        }

        return $md;
    }
}
