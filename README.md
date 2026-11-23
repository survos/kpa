# Kid Pan Alley archive

KPA stores songs, lyrics, recordings, source files, and YouTube video metadata in Doctrine. EasyAdmin provides authenticated editing; SearchBundle queries an Elasticsearch projection of the catalog.

## Development setup

The current baseline is PHP 8.5, Symfony 8.2.0-BETA1, API Platform 5, and EasyAdmin 5.9. The working branch is `main`.

```bash
git clone git@github.com:survos/kpa.git
cd kpa
composer install
```

Configure `DATABASE_URL` and application credentials in `.env.local`; do not overwrite an existing file. The normal application database is PostgreSQL. Tests use an isolated in-memory SQLite database.

For local bundle development, install from Packagist first, then link the local monorepo:

```bash
php ../mono/link "$PWD"
php bin/console cache:clear
symfony server:start -d
symfony open:local
```

Production uses the published bundle versions pinned in `composer.lock`; local symlinks are optional for bundle development. Review pending migrations and the target database before applying schema changes. Do not drop an existing database to reload the catalog.

The homepage links to `/admin`. Anonymous visitors are redirected to `/login`; access requires `ROLE_ADMIN`. The dashboard currently exposes Songs and Files.

## Temporary CSV recovery

The current local catalog was recovered from `~/Downloads/kpa-export.csv` on November 8, 2026: **2,324 songs, 783 with lyrics**. This is a temporary production export, not the authoritative GMU archive.

```bash
php bin/console app:import-song-export ~/Downloads/kpa-export.csv --dry-run
php bin/console app:import-song-export ~/Downloads/kpa-export.csv
php bin/console app:songs:embed --no-debug
php bin/console elastic:index:rebuild app_song --keep-old --no-debug
```

The importer preserves song IDs for future video associations, updates by ID, keeps existing values when export cells are empty, and recomputes lyric length. It ignores derived chorus/file counts, does not import videos or recordings, and bypasses legacy ORM indexing listeners. All database changes are transactional. Keep the CSV outside Git.

## Recovering and loading the catalog

See [Songs import](docs/SongsImport.md) for source folders, extraction, ingestion, and verification.

The expected source is the Kid Pan Alley Dropbox archive:

```text
Songs-Recordings/
└── -George Mason University archive working/
    ├── -Master Song List.xlsx
    └── lyrics/
        └── Lyrics KPA/
            ├── Lyrics by location/
            └── Lyrics individual songs/
```

The old Linux source was under `/media/tac/x10a/kpa-dropbox/`. That is historical, not a portable default. On November 8, 2026, that archive was not located in the bounded directory search of the mounted Mac `/Volumes/X10`; Dropbox recovery still requires signing into the account containing the KPA files. Do not assume the Mac X10 and Linux x10a have identical contents.

Once downloaded, set the actual `Songs-Recordings` directory in `.env.local`:

```dotenv
KPA_RECORDINGS_ROOT="/absolute/path/to/Songs-Recordings"
```

Start with the spreadsheet and lyrics folders rather than rescanning all audio:

```bash
castor songs:export-csv
castor songs:split-location
castor songs:split-individual
castor songs:enrich
php bin/console app:ingest-cho-dir var/data --dry --limit=10
castor songs:ingest
castor songs:status
```

Generated ChordPro files and metadata live in `var/data/<slug>/`. If that staging tree has already been recovered, reuse it and start with the dry-run ingestion step. Do not rerun extraction with `--force` over curated lyrics without reviewing the files first; the Castor split tasks currently use that flag.

## Videos

The existing YouTube loader is:

```bash
php bin/console app:load --video
```

It needs `YOUTUBE_API_KEY` and `YOUTUBE_CHANNEL`, fetches the channel through the YouTube API, and matches videos to existing songs. Load songs first. This is a live API import, not a local video-archive restore; it has not been exercised during the current archive recovery.

## Elasticsearch

Keep `survos/search-bundle` and `survos/elastic-bundle`. Configuration is in `config/packages/survos_search.yaml`: adapter `es`, prefix `kpa_`, connection from `ELASTICSEARCH_DSN`. The local default is `elasticsearch://127.0.0.1:9200`.

After importing the database records:

```bash
php bin/console elastic:index:create app_song
php bin/console elastic:index:populate app_song
php bin/console elastic:index:create app_file_asset
php bin/console elastic:index:populate app_file_asset
php bin/console elastic:index:status
```

The homepage Search links open `/entity/app_song/search` and `/entity/app_file_asset/search`. The November 11 spreadsheet/lyrics import contains 2,560 catalog records (2,157 with nonempty lyrics; 403 missing lyrics); the file index remains empty. Ordinary reindexing does not need `--drop`.

Keyword, semantic and hybrid search are active. AI was resumed on November 23, 2026 (`KPA_AI_ENABLED=1`) for the themed episodes; set it to `0` to pause embedding generation and semantic queries again.

## Tests

```bash
vendor/bin/phpunit
```

The suite creates its own SQLite schema where needed; no manual fixture loading or development database reset is required. It covers admin access and API filters. Four older crawl/import tests are currently skipped.

## Keyword, semantic, and hybrid song search

Open `/songs/search`. Choose Keyword, Semantic, Hybrid, or Compare all three (top 12 results per mode). The homepage Songs card links here. Try `shadow`, then `friendship and belonging` to compare literal words with meaning.

Song, Video, FileAsset, Audio, and Lyrics use generic field-bundle attributes for search metadata. Meilisearch packages, configuration, worker, and UI integrations are removed. SearchBundle supplies the Elasticsearch adapters; the application specializes song vector indexing and retrieval without changing shared bundles.

The local application database is PostgreSQL 18.4 (`kidpanalley`). Song vectors are stored durably in PostgreSQL JSONB, then projected into Elasticsearch as 768-dimensional cosine dense vectors. Retrieval uses Elasticsearch; this does not add a PostgreSQL vector-query engine. PHPUnit alone uses isolated SQLite.

Embeddings use Ink/Folio's local Ollama model, `nomic-embed-text:latest`, with `search_document:` and `search_query:` prefixes. Configure `KPA_OLLAMA_URL`, `KPA_EMBED_MODEL`, and `KPA_EMBED_REVISION`; the revision is the installed model digest and is checked before generation. Vectors are generated and cached by [survos/ai-embed-bundle](https://github.com/survos/ai-embed-bundle) in a DuckDB file outside the catalog database (`AI_EMBED_CACHE`, default `var/embeddings/kpa.duckdb`; keep it on persistent storage). The cache is keyed by the model profile and the exact input text, so a database reload, a re-import or a reindex reuses every vector whose song text has not changed: `app:songs:embed` only calls Ollama for new or edited songs. `Song::$embedding` is the projection the Elasticsearch index reads; a fingerprint covers source text, model, revision, dimensions and text preprocessing, and the command re-projects any song whose fingerprint is stale. Search queries are embedded by the bundle's Ollama provider directly: the cache file is locked by one process at a time, and a web request must not wait for it.

Production has neither Ollama-side generation nor DuckDB (the Heroku PHP buildpack has no `pdo_duckdb`), so vectors are made on a workstation and shipped through the shared bucket (`survos-embeddings` on Hetzner object storage, `EMBEDDINGS_S3_*`):

```bash
php bin/console app:songs:embed                        # workstation: generate what is missing
php bin/console app:songs:vectors:export --push        # workstation: upload survos-sites/kpa/song-vectors.jsonl.gz
php bin/console app:songs:vectors:import               # production: fill vectors whose song text is unchanged
php bin/console elastic:index:rebuild app_song --keep-old
```

The import refuses an archive made with another embedding profile, and only writes a vector where the installation's own song text gives the same fingerprint. Semantic search defaults to a minimum similarity of 0.6 (`SongSearch`): Nomic scores most songs above 0.35 for any query.

On November 23, 2026 the cache was seeded from the 2,559 vectors already on the songs, with no model call; a song whose vector was then cleared came back from the cache. Run the embedding command after imports/edits, then rebuild ES:

```bash
php bin/console app:songs:embed --no-debug
php bin/console elastic:index:rebuild app_song --keep-old --no-debug
```

The November 8 baseline had 2,324 indexed songs and 2,322 persisted embeddings. The November 11 raw-data recovery now has 2,560 songs and 2,157 nonempty lyrics. On November 23, 2026, `app:songs:embed` refreshed them: 2,559 of 2,560 songs have a vector (one is excluded pending lyrics review). `elastic:index:rebuild app_song --keep-old` then projected them into Elasticsearch (`kpa_song_20261123145849`). Elasticsearch refuses new indexes when the disk is above its 90% high watermark; with Podman, deleted volumes only free the Mac's disk after `podman machine ssh -- sudo fstrim -av`.

Two unusually long lyrics records are preserved but excluded from vectors pending source review: `runn-0-running-with-the-moon-tonight` (6,502 characters) and `runn-0-running-with-the-moon-tonight-inspiration` (10,115). The downloaded CSV itself contains multiple drafts, production notes, and generated research in these fields. The temporary recovery guard flags lyrics longer than 6,000 characters; it does not truncate or rewrite the source.

The additive migration `Version20261108160000` adds the vector cache columns. Review pending migrations before applying to an existing database. The code and data changes have only been exercised locally, not deployed.

The integrated `/search` page provides Lexical, Semantic, and Hybrid tabs with shared facets. Semantic controls the cosine cutoff; Hybrid adds a semantic candidate limit. Scores are shown on both integrated results and the `/songs/search` comparison page. The local search-bundle integration is required until its shared changes are released. The homepage links to the integrated page.

## Themed episodes

Two synthetic hosts, PaulBot and CherylBot, introduce KPA songs on a theme, play a short excerpt of each, and give its background from the catalog. See [docs/themed-episodes.md](docs/themed-episodes.md).

```bash
php bin/console app:episode:songs "winter holidays"                     # candidates by meaning, with their recordings
php bin/console app:episode:write data/episodes/holidays.json --output=docs/episodes/holidays-script.md
```

The writer needs `MISTRAL_API_KEY` in `.env.local`.

## Ask the catalog (chat) and MCP

`/ask` answers questions about the catalog with a Mistral agent (`kpa_chat` in
`config/packages/ai.yaml`, model `KPA_CHAT_MODEL`, prompt `config/prompts/kpa-chat.md`). The agent
searches and reads songs through tools, and every song it names links to its page. Under each answer,
"Looked up" lists the tool calls it made.

The same tools are an MCP server, `src/Mcp/SongTools.php`:

| Tool | What it returns |
| --- | --- |
| `find_songs` | songs closest in meaning to a question (cosine over the persisted song vectors, a boost when the title is named), optionally only with a recording or of one school |
| `get_song` | credits, subtitle, chorus, full lyrics, and recordings with playable URLs (`aiGenerated` marks Suno and AI arrangements) |
| `songs_by_school` | the songs of one school, optionally one year |
| `catalog_stats` | exact counts |

Connect over HTTP at `https://kpa.survos.com/mcp`, or over stdio with `php bin/console mcp:server kpa`.
Commands marked `#[AsAgentTool]` (for example `app_recordings_candidates`) are listed too, but require
`ROLE_ADMIN`.

## The podcast

`/episodes` is the podcast, with an RSS feed at `/episodes/feed.xml`. Each episode is written by
`app:episode:write` and voiced and assembled by `app:episode:publish`:

```bash
php bin/console app:episode:write data/episodes/holidays.json --output=docs/episodes/holidays-script.md
php bin/console app:episode:publish docs/episodes/holidays-script.json data/episodes/holidays.json holidays
```

Publishing voices every line with the host's stock voice (Mistral Voxtral, `KPA_VOICE_MODEL`); no real
person's voice is cloned. It cuts each excerpt from its recording at the brief's timestamps, with a
0.5 s fade in and a 1.5 s fade out, and levels voices and music to about -16 LUFS. It joins everything
into `<slug>.mp3`, with chapters (one per song), a WebVTT transcript and `episode.json` (credits).

The output goes to `KPA_EPISODES_DIR`. Publishing needs ffmpeg, so it runs on the workstation. Copy the
folder to production's storage (`/var/lib/dokku/data/storage/kpa/episodes/<slug>/`, owner 32767),
which the app sees as `/data/episodes`.
