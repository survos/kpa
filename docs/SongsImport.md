# Songs import and archive recovery

## Source and first download

The importer was built around this Dropbox subtree:

```text
Songs-Recordings/-George Mason University archive working/
```

Recover these first:

1. `-Master Song List.xlsx` — catalog metadata, worksheet `KPA Songs`.
2. `lyrics/Lyrics KPA/Lyrics by location/` — residency collections containing multiple songs.
3. `lyrics/Lyrics KPA/Lyrics individual songs/` — individual lyric documents.
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

## Recovery in progress — November 11, 2026

- The Dropbox connector and `kpa-dropbox` rclone remote are verified as
  `tac@kidpanalley.org`. For team folders use `kpa-dropbox:/Songs-Recordings`
  (the leading slash selects the team root).
- Dropbox desktop syncing was halted after it selected the internal-drive
  location. Do not resume it for this recovery. X10 must remain unencrypted.
- The confirmed destination is `/Volumes/X10/kpa-dropbox`. Do not substitute
  the internal Dropbox directory or assume the historical Linux path exists.
- Download only the canonical `-Master Song List.xlsx` and lyric source
  documents. Recordings already on S3 are outside this recovery scope. Never copy the GMU root or the full
  Recordings Archive recursively. Use explicit file lists and one-way
  `rclone copy`; do not use `rclone sync` to delete local material.
- The initial overly broad GMU transfer has ended; it downloaded 3,580 files
  (10.537 GiB), including extra residency material, and reported one inaccessible
  remote directory. This is not proof of complete raw catalog coverage. No
  further broad transfer is running. Extra downloaded files have not been deleted.
- GMU `lyrics` contains 1,483 files (323,644,146 bytes). The separate team
  `Recordings Archive` also contains recordings/stems; it is not inside GMU.
- The current master spreadsheet adds a `program` column. The exporter now maps
  source headers, preserves source worksheet/row provenance, and recognizes
  year-only dates. It exports all rows; administrative notes and duplicate
  records were reconciled before database ingestion.
- Do not run the old title-only enrichment/matching pipeline blindly: it drops
  duplicate-title CSV rows and uses fuzzy matching that can conflate songs.
- Import completed into the local KPA database: 236 created, 2,324 updated;
  2,560 total, 1,871 with nonempty lyrics, 689 with missing/blank lyrics.
  These are stored catalog counts; historical records were retained.
- The master contains 2,468 song rows after excluding administrative worksheet
  rows 2, 4, and 5. Reconciliation maps these to 2,384 catalog IDs, retaining
  176 pre-existing records outside the master. Matching uses normalized titles
  and school context; source-row-to-ID mapping is preserved in the audit.
- Existing DOCX splitting was staged separately per source document, then
  supplemented with non-AI text extraction of legacy Word/RTF sources. Song
  boundaries and credits constrain matching; ambiguous/unmatched sources were
  not converted into invented catalog records. Some source formats/documents
  still require manual review, so missing lyrics does not mean absent in Dropbox.
- The recovery directory `var/recovery/2026-11-11/` (outside Git) contains the
  database backup, source manifests, `prepare.py`, staged `ready.csv`,
  `reconciliation.json`, `missing-lyrics.csv`, and `verified-counts.json`.
  `app:import-song-export ready.csv --dry-run` validates the prepared input;
  without `--dry-run` it writes transactionally while bypassing AI listeners.
  The verification compared every imported title and lyric to the database.
- No recording links or S3 assets were changed. No new embeddings or generated
  summaries/keywords were produced.
- Embeddings, dense summaries, generated keywords, and all other AI processing
  are explicitly deferred until full raw song/lyrics coverage is verified.


### Second lyrics pass — November 11, 2026

Recovered lyrics for **286 additional catalog records**, reducing missing lyrics
from **689 to 403**. The database now contains **2,560 records**, **2,157 with
nonempty lyrics**. These are catalog-record counts, not a deduplicated count of
musical works.

This pass used only files already downloaded: legacy Word/RTF, DOCX, and textual
lyrics PDFs. Embedded previews from seven Pages documents were inspected too.
Reviewed title aliases cover spelling mistakes, missing articles, incomplete
spreadsheet titles, and suffixes such as `c`, `wf`, and `.doc`. School, author,
and source-document context supported the matches. Score-only PDFs, set lists,
wrong-school matches, uncertain fragments and writing notes were not accepted.
No source lyrics were generated, and no additional Dropbox transfers ran.

Artifacts are in `var/recovery/2026-11-11/lyrics-pass2/`:

- `recovered-matches.csv`: each updated record and its downloaded source.
- `approved.json`: exact text, source heading, match method and text-source hash.
- `missing-lyrics.csv`: current 403 unresolved records.
- `before-pass2.json`: pre-update values for rollback.
- `verified-counts.json`: database counts after the update.
- `approve.py`: reviewed boundaries and source-line validation.
- `load.php`: dry-run validation and transaction guarded against overwriting
  nonempty lyrics or creating records; it verifies every updated value afterward.

Only `lyrics` and `lyrics_length` changed. Song IDs, metadata, recordings and S3
links were preserved. Ordinary Elasticsearch search was refreshed. AI generation,
embeddings, dense summaries and generated keywords remain on hold; unresolved
records still need source reconciliation and do not necessarily lack files.
