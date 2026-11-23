<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asks the writer model for a themed episode, checks it (ScriptRules), and asks
 * once more with the problems found before giving up.
 */
final readonly class SongScriptWriter
{
    /** @param non-empty-string $model */
    public function __construct(
        #[Autowire(service: 'ai.platform.mistral')] private PlatformInterface $platform,
        private Cast $cast,
        private ScriptRules $rules,
        #[Autowire(env: 'EPISODE_WRITER_MODEL')] private string $model,
        #[Autowire('%kernel.project_dir%/config/prompts/episode-writer.md')] private string $promptFile,
    ) {
    }

    public function write(SongBrief $brief): SongScript
    {
        $messages = new MessageBag(
            Message::forSystem((string) file_get_contents($this->promptFile)),
            Message::ofUser($this->request($brief)),
        );
        $script = $this->ask($messages);

        if ([] !== $problems = $this->rules->check($script, $brief)) {
            $script = $this->ask($messages
                ->with(Message::ofAssistant((string) json_encode($script)))
                ->with(Message::ofUser("Problems found in your script:\n- ".implode("\n- ", $problems))));

            if ([] !== $problems = $this->rules->check($script, $brief)) {
                throw new \RuntimeException("The writer could not produce a valid script:\n- ".implode("\n- ", $problems));
            }
        }

        return $script;
    }

    private function ask(MessageBag $messages): SongScript
    {
        return $this->platform->invoke($this->model, $messages, ['response_format' => SongScript::class, 'temperature' => 0.4])->asObject(SongScript::class);
    }

    private function request(SongBrief $brief): string
    {
        $hosts = '';
        foreach ($brief->hosts as $id) {
            $host = $this->cast->hosts[$id] ?? throw new \InvalidArgumentException(sprintf('"%s" is not a host (hosts: %s).', $id, implode(', ', array_keys($this->cast->hosts))));
            $hosts .= sprintf("- id \"%s\", %s: %s Emotions: %s.\n", $host->id, $host->name, $host->persona, implode(', ', array_keys($host->voices)));
        }

        $songs = '';
        foreach ($brief->songs as $i => $song) {
            $songs .= sprintf("[%d] \"%s\"\n%s\nExcerpt played on air: %.0f seconds of the %s%s\n\n", $i + 1, $song->title, $song->facts, $song->excerptLength(), $song->performance, '' === $song->excerptLyrics ? '.' : ", the words sung: \"{$song->excerptLyrics}\"");
        }

        $others = '';
        foreach ($brief->sources as $i => $source) {
            $others .= sprintf("[%d] %s\n%s\n\n", \count($brief->songs) + $i + 1, $source['title'], $source['notes']);
        }

        $tone = $brief->tone ?? 'warm and playful, for families listening together';
        $words = $brief->minutes * ScriptRules::WORDS_PER_MINUTE;

        return <<<MESSAGE
            Show: "{$this->cast->title}", in English.
            Hosts:
            {$hosts}
            Theme: {$brief->theme}
            Tone: {$tone}
            Length of the talk: about {$words} words in all, the excerpts come on top.

            Songs, in the order they are played (each is also the numbered source of its facts):

            {$songs}
            MESSAGE.('' === $others ? '' : "Other sources:\n\n{$others}");
    }
}
