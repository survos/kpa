<?php

declare(strict_types=1);

namespace App\Episode;

/**
 * Rules the writer must follow, checked in code (after podcast-studio's ScriptChecker).
 * A cited source proves a line is backed by something, not that it is faithful to it:
 * that is the editor's review.
 */
final readonly class ScriptRules
{
    public const int WORDS_PER_MINUTE = 150;
    private const float MIN_LENGTH_RATIO = 0.6;
    private const float MAX_LENGTH_RATIO = 1.5;
    private const int MAX_LINE_CHARACTERS = 400;
    // episodes are heard later: relative days would be wrong by then
    private const string RELATIVE_DAYS = '/\b(today|tonight|tomorrow|yesterday|this (?:week|weekend|morning|evening|month|year)|last (?:night|week|month|year)|next (?:week|month|year))\b/i';
    // a voice does not read emphasis, and the transcript would show the marks
    private const string MARKUP = '/[*_`]/';

    public function __construct(private Cast $cast)
    {
    }

    /** @return list<string> problems, empty when the script is fine */
    public function check(SongScript $script, SongBrief $brief): array
    {
        $problems = [];

        if (\count($script->segments) !== \count($brief->songs)) {
            $problems[] = sprintf('The script has %d song segments, the episode has %d songs: one segment per song.', \count($script->segments), \count($brief->songs));
        }
        foreach ($script->segments as $i => $segment) {
            $n = $i + 1;
            if ($segment->song !== $n) {
                $problems[] = sprintf('Segment %d is about song %d: play the songs in the order given, segment %d is song %d.', $n, $segment->song, $n, $n);
            }
            if ([] === $segment->intro || [] === $segment->background) {
                $problems[] = sprintf('Segment %d needs lines both before and after its excerpt.', $n);
            }
            $cites = array_merge([], ...array_map(static fn (Line $line) => $line->sources, [...$segment->intro, ...$segment->background]));
            if (!\in_array($segment->song, $cites, true)) {
                $problems[] = sprintf('Segment %d gives no fact from the source of its song (source %d).', $n, $segment->song);
            }
        }
        if ([] === $script->opening || [] === $script->closing) {
            $problems[] = 'The script needs an opening and a closing.';
        }

        $hosts = array_intersect_key($this->cast->hosts, array_flip($brief->hosts));
        $words = 0;
        $speakers = [];
        $cited = false;
        foreach ($script->lines() as $i => $line) {
            $n = $i + 1;
            $host = $hosts[$line->host] ?? null;
            if (null === $host) {
                $problems[] = sprintf('Line %d: unknown host "%s" (hosts: %s).', $n, $line->host, implode(', ', array_keys($hosts)));
            } elseif (!isset($host->voices[$line->emotion])) {
                $problems[] = sprintf('Line %d: %s has no emotion "%s" (emotions: %s).', $n, $host->name, $line->emotion, implode(', ', array_keys($host->voices)));
            }
            if ('' === trim($line->text)) {
                $problems[] = sprintf('Line %d is empty.', $n);
            }
            if (preg_match(self::RELATIVE_DAYS, $line->text, $match)) {
                $problems[] = sprintf('Line %d says "%s": give the date itself, people listen later.', $n, $match[1]);
            }
            if (preg_match(self::MARKUP, $line->text, $match)) {
                $problems[] = sprintf('Line %d has markup ("%s"): write plain words only, a voice reads them.', $n, $match[0]);
            }
            if (mb_strlen($line->text) > self::MAX_LINE_CHARACTERS) {
                $problems[] = sprintf('Line %d is too long for a spoken line (%d characters, max %d).', $n, mb_strlen($line->text), self::MAX_LINE_CHARACTERS);
            }
            foreach ($line->sources as $source) {
                if ($source < 1 || $source > $brief->sourceCount()) {
                    $problems[] = sprintf('Line %d cites source %d, which does not exist (sources: 1 to %d).', $n, $source, $brief->sourceCount());
                }
            }
            $speakers[$line->host] = true;
            $cited = $cited || [] !== $line->sources;
            $words += str_word_count($line->text);
        }

        if ([] !== $silent = array_diff_key($hosts, $speakers)) {
            $problems[] = sprintf('%s never speaks: the episode is a dialogue between its two hosts.', implode(' and ', array_map(static fn (Host $host) => $host->name, $silent)));
        }
        if ('' === trim($script->summary)) {
            $problems[] = 'The summary is empty.';
        }
        if (!$cited) {
            $problems[] = 'No line cites a source: every fact must come from the sources.';
        }
        $target = $brief->minutes * self::WORDS_PER_MINUTE;
        if ($words < $target * self::MIN_LENGTH_RATIO || $words > $target * self::MAX_LENGTH_RATIO) {
            $problems[] = sprintf('The script has %d words, aim for about %d.', $words, $target);
        }

        return $problems;
    }
}
