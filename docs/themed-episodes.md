# Themed episodes of Kid Pan Alley songs

A countdown-style episode: two synthetic hosts, PaulBot and CherylBot, introduce a song from the catalog, play a short excerpt of it, give its background, and move on to the next song. One theme per episode: holiday songs, patriotic songs, songs about animals.

The prototype started in podcast-studio (a Symfony AI app that writes and voices two-host episodes) and moved here, where the songs, lyrics and recordings are.

Status: scripts are generated end to end, with each excerpt placed and timed. The first one: [episodes/holidays-script.md](episodes/holidays-script.md) (3 songs, about 2 minutes of talk plus 60 seconds of music). Voicing, audio assembly and the podcast library are next ([below](#next-steps)).

```bash
php bin/console app:episode:songs "winter holidays"      # 1. candidates for a theme, by meaning
# 2. pick the songs, a recording and excerpt times for each: data/episodes/<name>.json
php bin/console app:episode:write data/episodes/holidays.json --output=docs/episodes/holidays-script.md
```

## Segment structure

```
Opening       2–4 lines   hook, theme, how many songs
Song n        intro       2–4 lines: song, school, grade; the last line hands over ("here is the chorus")
              EXCERPT     15–25 s of the recording, fade in 0.5 s, fade out 1.5 s
              background  2–5 lines: reaction, then the story of the song from its card; the last line leads on
… one segment per song, in the order of the brief …
Closing       1–3 lines   sign-off
```

The excerpt's place is part of the structure, not a marker the model has to remember. The writer (`SongScriptWriter`, Mistral, structured output) returns a `SongScript`: `opening`, then `segments[]`, each with `song`, `intro[]` and `background[]`, then `closing`. The excerpt plays between `intro` and `background`.

`ScriptRules` checks the result. Its rules come from podcast-studio's `ScriptChecker`:

- known hosts and emotions;
- cited sources that exist;
- no markup;
- no relative dates ("today", "this year"): episodes are heard later;
- length;
- both hosts speak.

It adds the running-order rules: one segment per song, in order, lines on both sides of each excerpt, and each segment citing its own song's source at least once. On problems, the writer is asked once more with the list.

Timing budget: about 25 seconds of talk and 20 seconds of music per song.

| Songs | Talk | Music | Episode |
| --- | --- | --- | --- |
| 3 | ~2 min | ~1 min | ~3 min |
| 10 ("top ten") | ~4–5 min | ~3.5 min | ~8 min |

`SongBrief` takes 2 to 12 songs and 1 to 10 minutes of talk.

The hosts are configured in `config/packages/kpa_episodes.yaml`.

## Where each song's facts come from

The writer only sees the brief. `SongCardFactory` builds each song's card from the catalog; nothing is typed by hand except the choice of recording and the excerpt times. The writer gets the text, not a model's summary of it.

| Fact | Where |
| --- | --- |
| Title, school, year, catalog date | `Song::$title`, `$school`, `$year`, `$date` |
| Writers | `Song::$writers`, without the "K.P. Alley" house credit (see open questions) |
| Class, grade and co-writers | the ChordPro `{subtitle:}` in `Song::$lyrics`, e.g. "Paul Reisler, Paddy Dougherty, and Grymes Memorial School 4th graders" |
| Publisher | `Song::$publisher` |
| What the song is about | `ChordProInspector::plainLyrics()` |
| The excerpt's words | given in the episode JSON, from the chorus |
| The recording, its kind, its context | the `Audio` whose `FileAsset::$s3` is the chosen URL, its `variant`, and the last two folders of `FileAsset::$path`, e.g. "Christmas Album - 2023/Possible Songs" |
| Kid Pan Alley itself | the homepage text, as a source in the episode JSON |

Rules the prompt (`config/prompts/episode-writer.md`) adds for this catalog:

- Never name a child or a teacher, even when the lyric sheet credits them ("a second grade class at Savoy").
- No anecdotes from the writing session unless the card has them.
- The hosts are not the songwriters. They talk about Paul Reisler or Cheryl Toth in the third person.
- No more than one line of lyrics is quoted on air. The excerpt carries the song.

### Choosing the songs for a theme

`app:episode:songs "<theme>"` (`ThemeFinder`) embeds the theme with the same Ollama model as the songs (`SongEmbeddingService`, `search_query:` prefix, through survos/ai-embed-bundle's provider). It ranks songs by cosine similarity against the vectors persisted on `Song::$embedding`. It only considers songs that have lyrics and at least one recording that isn't AI-generated. Each candidate is listed with its recordings: kind, length and URL.

This reads Postgres, not Elasticsearch, so it works even when the search index is stale. For "winter holidays: Christmas, Hanukkah, Thanksgiving, snow", it ranks "There's Nothing Like Christmas", "On Christmas Day", "Four Seasons", "Waving Goodbye to Winter", "Christmas In July"… The ranking finds songs a keyword search misses ("Frosty's Cavern", "It's Only September (But Let It Snow)").

**Skip AI-generated recordings.** The archive holds Suno arrangements next to the real ones: "recording Suno/…", "AI recordings of KPA:PR songs/…". `SongCardFactory::performance()` labels them "AI-generated arrangement", and `ThemeFinder` skips songs that only have those.

## How excerpts are chosen and mixed in

**Which recording.** In order of preference: `gv` (guitar and vocal, the clearest words), then a studio mix, then `gtr`, then `live` (concert recordings; lovely, but often rough). Never an AI arrangement.

**Which part.** The first full chorus (`Song::$chorus`, the first `{start_of_chorus}` block): it names the song. Length is 15 to 25 seconds, cut on a phrase end, with a 0.5 s fade-in and a 1.5 s fade-out.

**Finding the timestamps.** The lyrics say which words; Whisper says when. VoiceStudio's `/v1/audio/transcriptions` (`whisper-1`, at `127.0.0.1:3900`) worked like this:

1. A single pass over the whole song **does not work over music**. It returned one segment for the 3-minute Thanksgiving mix and nothing at all for the 79-second Christmas demo. That looks like voice-activity filtering.
2. Overlapping 12-second chunks every 10 seconds, then 4-second chunks every 2 seconds around the first chorus, do work: each chunk's text locates a lyric line to about ±1 s.
3. Whisper invents lines over instrumental bars: "Thank you." and "We'll see you next time." both appeared. Only a chunk that matches the catalog lyrics counts.

The holiday excerpts (`data/episodes/holidays.json`):

| Song | Recording | Excerpt |
| --- | --- | --- |
| Every Day Should Be A Thanksgiving | `every-day-should-be-a-thanksgiving-recording.mp3` (studio mix, 3:11) | 0:11.5 → 0:31.0 |
| Going To My Cousins For Christmas | `going-to-my-cousins-for-christmas-gv.mp3` (gv, 1:19) | 0:07.5 → 0:25.5 |
| Waving Goodbye to Winter | `waving-goodbye-to-winter-gv.mp3` (gv, 2:22) | 0:06.0 → 0:28.5 |

Cut with:

```bash
ffmpeg -ss 11.5 -t 19.5 -i every-day-should-be-a-thanksgiving-recording.mp3 \
  -af "afade=t=in:d=0.5,afade=t=out:st=18:d=1.5" -b:a 128k x1-thanksgiving.mp3
```

**Mixing into the episode** (not built yet):

- Cut each excerpt once and re-encode it to the voice clips' sample rate and channels. Normalize its loudness to the voices (about −16 LUFS) so the music doesn't jump out.
- Voice only the lines: one request per line, with the host's voice for its emotion.
- Join everything in running order, with a short pause on each side of an excerpt. podcast-studio's `Mp3Joiner` does this in PHP without ffmpeg, once all clips share one format.
- The timings then drive the chapters, one per song starting at its intro. The VTT transcript shows each excerpt as a cue (`[Music: chorus of "Waving Goodbye to Winter"]`). The show notes credit the writers and the school for each excerpt.

## Lyrics for songs that only have audio

The catalog has 403 songs without lyrics. Whisper through VoiceStudio is a reasonable first draft only for the clearest `gv` recordings, and only in chunks (see above). On a mix with drums or a choir it drops and invents words. Its output would be a draft for a human to correct, never a source the hosts quote. Separating the vocals first (for example with Demucs) would likely help a lot; it has not been tried.

## Voices

PaulBot and CherylBot are synthetic hosts "inspired by" the duo, with personas that describe the bots' roles, not the real people. For now they use Voxtral stock voices (`en_paul_*` and `gb_jane_*`; `en_paul` is Mistral's own stock voice, not a clone of anyone). The local path is VoiceStudio through the Generic bridge text-to-speech PR (`tacman:generic-text-to-speech`); the Generic platform is already installed here, unconfigured. **No real person's voice is cloned.** Voices modeled on Paul Reisler and Cheryl Toth wait for their explicit consent, which Tac is arranging.

## Next steps

1. **Excerpt finder.** Transcribe a recording in chunks, match the chunks to `Song::$chorus` with fuzzy matching, and propose start and end times for the editor to hear and nudge.
2. **Voicing and assembly**, as above.
3. **A podcast library.** An `Episode` entity (theme, script JSON, MP3, duration, published date) linked to the songs it plays, with a page per episode and an RSS feed with transcript and chapters (podcast-studio has the feed, VTT and chapter code to copy from).
4. **Brief from the finder.** Pick songs in the `app:episode:songs` output, then write the episode JSON from that pick (or an EasyAdmin action that does it), instead of typing the JSON.

## Open questions

1. What is "K.P. Alley" in `writers` (on 1,479 songs)? It is kept off the air until we know.
2. Which credit wins when `writers` and the lyric sheet's subtitle disagree? For Savoy, `writers` lists Paul Reisler and Sahffi Lynne, and the subtitle adds Cheryl Toth.
3. May episodes use any recording on the public S3 bucket, or only the ones cleared for release (for example the "Latest Mixes" and album folders)?
