<?php

declare(strict_types=1);

namespace App\Episode;

use App\Catalog\SongFinder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a written episode (the writer's script and its brief) into one MP3: every line voiced
 * with its host's stock voice, each excerpt cut from the song's recording at the brief's
 * timestamps with fades, everything levelled to about -16 LUFS and joined with short pauses.
 * Writes the MP3, a WebVTT transcript and episode.json (chapters, credits) to the episodes
 * directory, where EpisodeController serves them and the RSS feed.
 *
 * Needs ffmpeg and ffprobe, so it runs where they are installed (the workstation), and the
 * result is copied to production's episodes directory.
 */
final readonly class EpisodeProducer
{
    private const RATE = 44100;
    private const LINE_PAUSE = 0.35;
    private const EXCERPT_PAUSE = 0.7;

    public function __construct(
        private Speech $speech,
        private Cast $cast,
        private SongFinder $songs,
        private HttpClientInterface $http,
        private Filesystem $filesystem,
        #[Autowire(env: 'resolve:KPA_EPISODES_DIR')] private string $episodesDir,
    ) {
    }

    /**
     * @param array<string, mixed> $script the writer's SongScript as JSON
     * @param array<string, mixed> $brief  theme, hosts and per song its id, recording, start and end
     *
     * @return array<string, mixed> the published episode (episode.json)
     */
    public function produce(string $slug, array $script, array $brief, ?callable $progress = null): array
    {
        $progress ??= static function (string $message): void {};
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug)) {
            throw new \InvalidArgumentException('The slug takes lowercase letters, digits and hyphens.');
        }
        $dir = $this->episodesDir.'/'.$slug;
        $work = $dir.'/work';
        $this->filesystem->mkdir($work);

        // The running order: talk lines and excerpts, each with the chapter it opens.
        $items = [];
        foreach ($script['opening'] as $i => $line) {
            $items[] = ['line' => $line, 'chapter' => 0 === $i ? 'Opening' : null];
        }
        foreach ($script['segments'] as $segment) {
            $song = $brief['songs'][$segment['song'] - 1] ?? throw new \InvalidArgumentException(sprintf('The script names song %d, the brief has %d.', $segment['song'], count($brief['songs'])));
            $card = $this->songs->get($song['song']) ?? throw new \InvalidArgumentException('Unknown song '.$song['song']);
            foreach ($segment['intro'] as $i => $line) {
                $items[] = ['line' => $line, 'chapter' => 0 === $i ? $card['title'] : null];
            }
            $items[] = ['excerpt' => $song, 'card' => $card];
            foreach ($segment['background'] as $line) {
                $items[] = ['line' => $line];
            }
        }
        foreach ($script['closing'] as $i => $line) {
            $items[] = ['line' => $line, 'chapter' => 0 === $i ? 'Closing' : null];
        }

        // Voice every line; the HTTP client runs the requests concurrently.
        $progress(sprintf('Voicing %d lines with %s', count(array_filter($items, static fn ($i) => isset($i['line']))), $this->speech->model()));
        $results = [];
        foreach ($items as $n => $item) {
            if (isset($item['line'])) {
                $results[$n] = $this->speech->start($item['line']['text'], $this->voice($item['line']));
            }
        }
        foreach ($results as $n => $result) {
            $raw = $work.sprintf('/%03d-voice.mp3', $n);
            $this->filesystem->dumpFile($raw, $this->speech->mp3($result));
            $this->ffmpeg(['-i', $raw, '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', ...$this->pcm()], $items[$n]['wav'] = $work.sprintf('/%03d.wav', $n));
        }

        // Cut each excerpt from its recording.
        foreach ($items as $n => $item) {
            if (!isset($item['excerpt'])) {
                continue;
            }
            $song = $item['excerpt'];
            $progress(sprintf('Cutting "%s" %.1f to %.1f s', $item['card']['title'], $song['start'], $song['end']));
            $source = $work.'/'.md5($song['recording']).'.mp3';
            if (!is_file($source)) {
                $this->filesystem->dumpFile($source, $this->http->request('GET', $song['recording'])->getContent());
            }
            $length = $song['end'] - $song['start'];
            $this->ffmpeg(['-ss', (string) $song['start'], '-t', (string) $length, '-i', $source,
                '-af', sprintf('afade=t=in:d=0.5,afade=t=out:st=%.2f:d=1.5,loudnorm=I=-16:TP=-1.5:LRA=11', max(0, $length - 1.5)), ...$this->pcm()], $items[$n]['wav'] = $work.sprintf('/%03d.wav', $n));
        }

        // Join with pauses, keeping the time of every item for the chapters and the transcript.
        $short = $this->silence($work, self::LINE_PAUSE);
        $long = $this->silence($work, self::EXCERPT_PAUSE);
        $list = [];
        $clock = 0.0;
        $chapters = [];
        $cues = [];
        foreach ($items as $n => $item) {
            $excerpt = isset($item['excerpt']);
            if ($n > 0) {
                $pause = $excerpt || isset($items[$n - 1]['excerpt']) ? $long : $short;
                $list[] = $pause;
                $clock += $this->duration($pause);
            }
            if (null !== ($item['chapter'] ?? null)) {
                $chapters[] = ['start' => round($clock, 2), 'title' => $item['chapter']];
            }
            $length = $this->duration($item['wav']);
            $cues[] = ['start' => $clock, 'end' => $clock + $length, 'text' => $excerpt
                ? sprintf('[Music: "%s", %s%s]', $item['card']['title'], $item['card']['school'] ?? 'Kid Pan Alley', isset($item['card']['year']) ? ', '.$item['card']['year'] : '')
                : sprintf('<v %s>%s', $this->cast->hosts[$item['line']['host']]->name ?? $item['line']['host'], $item['line']['text'])];
            $list[] = $item['wav'];
            $clock += $length;
        }
        $concat = $work.'/concat.txt';
        $this->filesystem->dumpFile($concat, implode("\n", array_map(static fn (string $f) => "file '".str_replace("'", "'\\''", $f)."'", $list))."\n");

        $mp3 = $dir.'/'.$slug.'.mp3';
        $this->ffmpeg(['-f', 'concat', '-safe', '0', '-i', $concat, '-c:a', 'libmp3lame', '-b:a', '128k',
            '-metadata', 'title='.$script['title'], '-metadata', 'artist='.$this->cast->title, '-metadata', 'album='.$this->cast->title], $mp3);
        $this->filesystem->dumpFile($dir.'/'.$slug.'.vtt', $this->vtt($cues));

        $episode = [
            'slug' => $slug,
            'title' => $script['title'],
            'summary' => $script['summary'] ?? $brief['theme'],
            'theme' => $brief['theme'],
            'published' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'seconds' => round($this->duration($mp3), 1),
            'bytes' => filesize($mp3),
            'hosts' => array_values(array_map(fn (string $id) => ['name' => $this->cast->hosts[$id]->name, 'persona' => $this->cast->hosts[$id]->persona], $brief['hosts'])),
            'chapters' => $chapters,
            'songs' => array_values(array_map(fn (array $item) => [
                'id' => $item['card']['id'],
                'title' => $item['card']['title'],
                'school' => $item['card']['school'] ?? null,
                'year' => $item['card']['year'] ?? null,
                'writers' => $item['card']['subtitle'] ?? $item['card']['writers'] ?? null,
                'url' => $item['card']['url'],
                'recording' => $item['excerpt']['recording'],
                'excerpt' => [$item['excerpt']['start'], $item['excerpt']['end']],
            ], array_filter($items, static fn (array $i) => isset($i['excerpt'])))),
        ];
        $this->filesystem->dumpFile($dir.'/episode.json', json_encode($episode, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        $this->filesystem->remove($work);

        return $episode;
    }

    /** @param array{host: string, emotion: string} $line */
    private function voice(array $line): string
    {
        $host = $this->cast->hosts[$line['host']] ?? throw new \InvalidArgumentException('Unknown host '.$line['host']);

        return $host->voices[$line['emotion']] ?? $host->voices['neutral'] ?? reset($host->voices);
    }

    /** @return list<string> the ffmpeg output options every clip shares, so the concat needs no re-sampling */
    private function pcm(): array
    {
        return ['-ar', (string) self::RATE, '-ac', '2', '-c:a', 'pcm_s16le'];
    }

    private function silence(string $work, float $seconds): string
    {
        $file = sprintf('%s/silence-%d.wav', $work, (int) ($seconds * 1000));
        if (!is_file($file)) {
            $this->ffmpeg(['-f', 'lavfi', '-i', sprintf('anullsrc=r=%d:cl=stereo', self::RATE), '-t', (string) $seconds, ...$this->pcm()], $file);
        }

        return $file;
    }

    /** @param list<string> $args */
    private function ffmpeg(array $args, string $output): void
    {
        (new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', ...$args, $output]))->setTimeout(300)->mustRun();
    }

    private function duration(string $file): float
    {
        $process = (new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]))->mustRun();

        return (float) trim($process->getOutput());
    }

    /** @param list<array{start: float, end: float, text: string}> $cues */
    private function vtt(array $cues): string
    {
        $at = static fn (float $s) => sprintf('%02d:%02d:%06.3f', intdiv((int) $s, 3600), intdiv((int) $s, 60) % 60, fmod($s, 60));
        $out = "WEBVTT\n";
        foreach ($cues as $n => $cue) {
            $out .= sprintf("\n%d\n%s --> %s\n%s\n", $n + 1, $at($cue['start']), $at($cue['end']), $cue['text']);
        }

        return $out;
    }
}
