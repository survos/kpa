# Songs import and archive recovery

## Source and first download

The importer was built around this Dropbox subtree:

```text
Songs-Recordings/-George Mason University archive working/
```

Recover these first:

1. `-Master Song List.xlsx` — catalog metadata, worksheet `KPA Songs`.
2. `Lyrics KPA/Lyrics by location/` — residency collections containing multiple songs.
3. `Lyrics KPA/Lyrics individual songs/` — individual lyric documents.
4. Selected audio and charts after song matching has been verified.

The expected folders are established by the existing import code; their availability in the current Dropbox account still needs verification. The historical Linux root `/media/tac/x10a/kpa-dropbox/` is not assumed to exist on macOS. Set `KPA_RECORDINGS_ROOT` in `.env.local` to the downloaded `Songs-Recordings` directory. Castor validates it before source operations.

## Prefer an existing staging tree

Look for `<slug>/<slug>-lyrics.cho` and `<slug>/<slug>-metadata.json`. These are the current ingestion artifacts. `app:ingest-cho-dir` creates or updates Songs using ChordPro directives and optional metadata sidecars, and stores the ChordPro text in `Song.lyrics`.

```bash
php bin/console app:ingest-cho-dir /absolute/path/to/staging --dry --limit=10
php bin/console app:ingest-cho-dir /absolute/path/to/staging
```

Audio symlinks made on Linux may still point at `/media/tac/x10a/...`. Recovering the link itself does not recover its target. Check targets before audio ingestion; rebuild the scan and links for the current source location if necessary.

## Build staging from the source documents

```bash
castor songs:export-csv
castor songs:split-location
castor songs:split-individual
castor songs:enrich
```

These tasks export the master spreadsheet to `data/master-song-list.csv`, split DOCX collections into `var/data/<slug>/<slug>-lyrics.cho`, and add metadata sidecars. The splitter exists in `src/Command/SplitLyricsDocxCommand.php`; the earlier documentation describing it as unimplemented was obsolete.

The split tasks use `--force` and overwrite matching generated lyrics. For a cautious first extraction, invoke the command directly without that flag:

```bash
php bin/console app:split-lyrics-docx "/absolute/path/to/Lyrics by location" --output-dir=var/data --no-preview
```

Inspect representative lyrics, school/year attribution, and song boundaries before ingestion. The current bulk splitter handles DOCX; legacy DOC, RTF, and Pages files are not interchangeable inputs.

`castor songs:split-residency` additionally targets `!!Residency documentation/Residencies current FY25-26`. Verify that folder before using it; it is a specific historical year, not an automatically selected current residency.

## Ingest songs, then related audio

```bash
php bin/console app:ingest-cho-dir var/data --dry --limit=10
castor songs:ingest
castor songs:status
```

After the desired audio folders are available:

```bash
castor songs:scan-audio
castor songs:link-audio
php bin/console app:ingest-audio var/data --jsonl=data/audio.jsonl --dry --limit=10
castor songs:ingest-audio
```

The scan writes reusable file facts to `data/audio.jsonl`; the linking step associates recordings with staged song folders. Audio ingestion creates `FileAsset` and `Audio` records. Avoid `--reset` for normal imports. The all-in-one `songs:all` task includes the historical residency folder and audio scan, so use individual stages for a selective recovery.

## Legacy paths

- `app:load-data` is not the current command; the registered command is `app:load`.
- `app:load --songs` reads the repository's older `data/kpa-songs.xlsx`; it does not select the recovered GMU master spreadsheet.
- `app:load --lyrics` still has a TODO where persistence should happen. Do not use it as a successful lyrics restore.
- `app:ingest-lyrics-jsonl` and `app:link-lyrics` are alternate backfills for older extracted JSONL or `FileAsset.lyricsCandidates`; they are not required for ChordPro ingestion.
- `app:load --video` fetches live YouTube metadata with configured credentials. Run it after songs; it does not restore local video files.
- Meilisearch settings/populate commands are no longer the search setup for this application.

## Verify and index

```bash
castor songs:status
php bin/console dbal:run-sql "SELECT COUNT(*) AS songs_with_lyrics FROM song WHERE lyrics IS NOT NULL AND lyrics <> ''"
php bin/console elastic:index:populate app_song
php bin/console elastic:index:populate app_file_asset
php bin/console elastic:index:status
```

Create missing ES indexes with `elastic:index:create` first, as documented in the README. Verify representative records in EasyAdmin and SearchBundle before treating the recovery as complete. Keep source documents, staged lyrics, scans, and databases outside Git; they are archive data, not application source.
