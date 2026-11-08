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

The current EZ header integration uses unpublished local changes in `../mono/bu/ez-bundle`; use the linker for this development setup. Review pending migrations and the target database before applying schema changes. Do not drop an existing database to reload the catalog.

The homepage links to `/admin`. Anonymous visitors are redirected to `/login`; access requires `ROLE_ADMIN`. The dashboard currently exposes Songs and Files. `/ez-meili` is no longer the application dashboard.

## Temporary CSV recovery

The current local catalog was recovered from `~/Downloads/kpa-export.csv` on November 8, 2026: **2,324 songs, 783 with lyrics**. This is a temporary production export, not the authoritative GMU archive.

```bash
php bin/console app:import-song-export ~/Downloads/kpa-export.csv --dry-run
php bin/console app:import-song-export ~/Downloads/kpa-export.csv
php bin/console elastic:index:populate app_song
```

The importer preserves song IDs for future video associations, updates by ID, keeps existing values when export cells are empty, and recomputes lyric length. It ignores derived chorus/file counts, does not import videos or recordings, and bypasses legacy ORM indexing listeners. All database changes are transactional. Keep the CSV outside Git.

## Recovering and loading the catalog

See [Songs import](docs/SongsImport.md) for source folders, extraction, ingestion, and verification.

The expected source is the Kid Pan Alley Dropbox archive:

```text
Songs-Recordings/
└── -George Mason University archive working/
    ├── -Master Song List.xlsx
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

The homepage Search links open `/entity/app_song/search` and `/entity/app_file_asset/search`. The local song index now contains the 2,324 recovered songs; the file index remains empty. Ordinary reindexing does not need `--drop`.

Semantic retrieval is not configured for KPA yet. SearchBundle's ES adapter supports vector/hybrid queries, but this application still needs an embedding profile, persisted vectors, and their ES projection. Installing the bundle does not enable semantic search automatically.

## Tests

```bash
vendor/bin/phpunit
```

The suite creates its own SQLite schema where needed; no manual fixture loading or development database reset is required. It covers admin access and API filters. Four older crawl/import tests are currently skipped.
