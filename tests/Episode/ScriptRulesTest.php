<?php

declare(strict_types=1);

namespace App\Tests\Episode;

use App\Episode\Cast;
use App\Episode\Line;
use App\Episode\ScriptRules;
use App\Episode\SongBrief;
use App\Episode\SongCard;
use App\Episode\SongScript;
use App\Episode\SongSegment;
use PHPUnit\Framework\TestCase;

final class ScriptRulesTest extends TestCase
{
    private const string WORDS = 'with enough spoken words to make this line count towards the length';

    public function testAcceptsOneSegmentPerSongInOrder(): void
    {
        self::assertSame([], self::rules()->check(self::script([1, 2]), self::brief()));
    }

    public function testRejectsSongsOutOfOrderOrMissing(): void
    {
        $problems = self::rules()->check(self::script([2]), self::brief());

        self::assertStringContainsString('1 song segments, the episode has 2 songs', $problems[0]);
        self::assertStringContainsString('Segment 1 is about song 2', $problems[1]);
    }

    public function testRejectsASegmentThatCitesNothingFromItsSong(): void
    {
        $script = self::script([1, 2]);
        $script = new SongScript($script->title, $script->opening, [
            $script->segments[0],
            new SongSegment(2, [new Line('paulbot', 'excited', 'Next one '.self::WORDS.'.', [])], [new Line('cherylbot', 'neutral', 'Nothing about it '.self::WORDS.'.', [1])]),
        ], $script->closing, $script->summary);

        self::assertContains('Segment 2 gives no fact from the source of its song (source 2).', self::rules()->check($script, self::brief()));
    }

    public function testRejectsRelativeYearsUnknownEmotionsAndMissingSources(): void
    {
        $script = self::script([1, 2]);
        $script = new SongScript($script->title, [
            new Line('paulbot', 'sarcasm', 'Recorded this year '.self::WORDS.'.', [9]),
            ...$script->opening,
        ], $script->segments, $script->closing, $script->summary);

        $problems = self::rules()->check($script, self::brief());

        self::assertContains('Line 1: PaulBot has no emotion "sarcasm" (emotions: neutral, excited).', $problems);
        self::assertContains('Line 1 says "this year": give the date itself, people listen later.', $problems);
        self::assertContains('Line 1 cites source 9, which does not exist (sources: 1 to 3).', $problems);
    }

    public function testExcerptTimes(): void
    {
        $song = self::brief()->songs[0];

        self::assertSame('0:11.5 → 0:31.0', $song->excerptAt());
        self::assertSame(19.5, $song->excerptLength());
    }

    public function testRejectsAnExcerptThatEndsBeforeItStarts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SongCard('a', 'A', 'Facts.', 'https://kpa-songs.s3.amazonaws.com/a.mp3', 'recording', 20.0, 10.0);
    }

    private static function rules(): ScriptRules
    {
        return new ScriptRules(new Cast([
            'title' => 'Test songbook',
            'hosts' => [
                'paulbot' => ['name' => 'PaulBot', 'persona' => 'The enthusiast.', 'voices' => ['neutral' => 'a', 'excited' => 'b']],
                'cherylbot' => ['name' => 'CherylBot', 'persona' => 'The curious one.', 'voices' => ['neutral' => 'c']],
            ],
        ]));
    }

    /** @param list<int> $songs */
    private static function script(array $songs): SongScript
    {
        $segments = array_map(static fn (int $n) => new SongSegment(
            $n,
            [new Line('paulbot', 'excited', "Song {$n} comes next ".self::WORDS.'.', [$n])],
            [new Line('cherylbot', 'neutral', "That was song {$n} ".self::WORDS.'.', [$n]), new Line('paulbot', 'neutral', 'And now '.self::WORDS.'.', [])],
        ), $songs);

        return new SongScript(
            'Two songs',
            [new Line('paulbot', 'excited', 'Welcome to two songs '.self::WORDS.'.', []), new Line('cherylbot', 'neutral', 'Two of them '.self::WORDS.'.', [3])],
            $segments,
            [new Line('cherylbot', 'neutral', 'Goodbye '.self::WORDS.'.', [])],
            'Two songs, two schools.',
        );
    }

    private static function brief(): SongBrief
    {
        $song = static fn (string $title, float $start, float $end) => new SongCard(strtolower($title), $title, "Facts about {$title}.", 'https://kpa-songs.s3.amazonaws.com/a.mp3', 'guitar and vocal version', $start, $end);

        return new SongBrief('Two songs', [$song('Song A', 11.5, 31.0), $song('Song B', 7.5, 25.5)], [['title' => 'Kid Pan Alley', 'notes' => 'Songs written with children.']], ['paulbot', 'cherylbot'], 1);
    }
}
