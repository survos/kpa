<?php

use Castor\Attribute\AsTask;

use function Castor\{import, io, fs, capture, run, load_dot_env};

#[AsTask(description: 'Welcome to Castor!')]
function hello(): void
{
    $currentUser = capture('whoami');

    io()->title(sprintf('Hello %s!', $currentUser));
}

#[AsTask(description: 'Purge and re-create the database')]
function reset_database()
{
    // this is only true if sqlite!
    $database = 'kpa';
//    $process = run([...get_console(), 'doctrine:database:drop', '--force']);
//    io()->info($process->getOutput());
//    run(join(' ', get_console()) .  ' doctrine:database:create');

    $process = run([...get_console(), 'doctrine:schema:update', '--force']);
    io()->info($process->getOutput());

    $process = run([...get_console(), 'app:load', '-v']);
    io()->info($process->getOutput());

    //    $process = run(join(' ', get_console()) .  ' doctrine:database:drop --force');
//    io()->info($process->getOutput());
//symfony console doctrine:database:drop --force && symfony console doctrine:database:create
//symfony console doctrine:migrations:migrate -n
//#symfony console d:schema:update --force --complete
//bin/create-admins.sh
//symfony console app:load-data -v


}

function get_console(): array
{
    $env = load_dot_env();
    $console =  ($env['APP_ENV'] === 'prod') ? ['bin/console']: ['symfony', 'console'];
    return $console;
}

// ---------------------------------------------------------------------------
// Songs pipeline — orchestrates split → enrich → ingest end-to-end.
// Each task streams the underlying Symfony command's output (incl. progress
// bars) so slow stages like the bulk docx split are visible live.
// ---------------------------------------------------------------------------

const SONGS_STAGING_DIR = 'var/data';
const SONGS_MASTER_CSV = 'data/master-song-list.csv';
const SONGS_AUDIO_JSONL = 'data/audio.jsonl';

/** Resolve the downloaded archive on this machine, rather than a fixed Linux mount. */
function songs_recordings_root(): string
{
    $env = load_dot_env();
    $root = trim($env['KPA_RECORDINGS_ROOT'] ?? '');
    if ($root === '' || !is_dir($root)) {
        throw new \RuntimeException('Set KPA_RECORDINGS_ROOT in .env.local to the downloaded Songs-Recordings directory.');
    }

    return rtrim($root, '/');
}

function songs_gmu_root(): string
{
    return songs_recordings_root() . '/-George Mason University archive working';
}

#[AsTask(namespace: 'songs', name: 'export-csv', description: 'Re-export the Master Song List .xlsx → data/master-song-list.csv')]
function songs_export_csv(): void
{
    run([...get_console(), 'app:export-master-songlist-csv', songs_gmu_root() . '/-Master Song List.xlsx']);
}

#[AsTask(namespace: 'songs', name: 'split-location', description: 'Bulk-split Lyrics KPA/Lyrics by location/*.docx → var/data/<slug>/<slug>-lyrics.cho (slow — Dropbox drive)')]
function songs_split_location(): void
{
    io()->title('Bulk split: Lyrics by location');
    io()->note('Source is on the x10a drive — expect this to take a few minutes.');
    run([...get_console(), 'app:split-lyrics-docx', songs_gmu_root() . '/lyrics/Lyrics KPA/Lyrics by location', '--force', '--no-preview']);
}

#[AsTask(namespace: 'songs', name: 'split-residency', description: 'Bulk-split current residency .docx files → var/data/')]
function songs_split_residency(): void
{
    io()->title('Bulk split: Residencies current FY25-26');
    run([...get_console(), 'app:split-lyrics-docx', songs_gmu_root() . '/!!Residency documentation/Residencies current FY25-26', '--force', '--no-preview']);
}

#[AsTask(namespace: 'songs', name: 'split-individual', description: 'Bulk-split Lyrics KPA/Lyrics individual songs/*.docx → var/data/')]
function songs_split_individual(): void
{
    io()->title('Bulk split: Lyrics individual songs');
    run([...get_console(), 'app:split-lyrics-docx', songs_gmu_root() . '/lyrics/Lyrics KPA/Lyrics individual songs', '--force', '--no-preview']);
}

#[AsTask(namespace: 'songs', name: 'enrich', description: 'Drop <slug>-metadata.json next to each <slug>-lyrics.cho + create dirs for CSV-only songs')]
function songs_enrich(): void
{
    run([...get_console(), 'app:enrich-song-dirs-from-csv', SONGS_STAGING_DIR, '--csv=' . SONGS_MASTER_CSV, '--force', '--create-dirs']);
}

#[AsTask(namespace: 'songs', name: 'ingest', description: 'Walk var/data/ and create/update Song records from .cho + metadata.json')]
function songs_ingest(): void
{
    run([...get_console(), 'app:ingest-cho-dir', SONGS_STAGING_DIR]);
}

#[AsTask(namespace: 'songs', name: 'scan-audio', description: 'Scan Songs-Recordings tree to data/audio.jsonl (pass --probe=2 for ffprobe metadata — much slower)')]
function songs_scan_audio(int $probe = 0): void
{
    io()->title('Scanning audio files from Songs-Recordings');
    io()->note('Source is on the x10a drive — this may take a few minutes.');
    if ($probe > 0) {
        // ffprobe runs per file over the Dropbox mount; it dominates the scan.
        io()->note(sprintf('Probe level %d — expect this to take much longer.', $probe));
    }
    run([...get_console(), 'import:dir', songs_recordings_root(),
        '--output=' . SONGS_AUDIO_JSONL,
        '--extensions=mp3,wav,aif,aiff,flac,m4a,ogg,wma',
        '--exclude-dirs=Cache,History',
        ...($probe > 0 ? ['--probe=' . $probe] : []),
    ]);
}

#[AsTask(namespace: 'songs', name: 'link-audio', description: 'Read data/audio.jsonl and symlink matched audio into var/data/<slug>/')]
function songs_link_audio(): void
{
    run([...get_console(), 'app:link-audio', SONGS_AUDIO_JSONL, '--staging=' . SONGS_STAGING_DIR]);
}

#[AsTask(namespace: 'songs', name: 'ingest-audio', description: 'Create FileAsset + Audio DB records from audio files in var/data/<slug>/')]
function songs_ingest_audio(): void
{
    run([...get_console(), 'app:ingest-audio', SONGS_STAGING_DIR]);
}

#[AsTask(namespace: 'songs', name: 'clean', description: 'Wipe var/data/<song-slug> dirs (keeps unrelated files in var/data)')]
function songs_clean(): void
{
    $base = SONGS_STAGING_DIR;
    if (!is_dir($base)) {
        io()->warning("$base does not exist");
        return;
    }
    $removed = 0;
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $songDir) {
        fs()->remove($songDir);
        $removed++;
    }
    io()->success("Removed $removed song dirs from $base");
}

#[AsTask(namespace: 'songs', name: 'status', description: 'Show counts: .cho files on disk, Song/Audio/FileAsset records in DB')]
function songs_status(): void
{
    $base = SONGS_STAGING_DIR;
    $choCount = is_dir($base) ? count(glob($base . '/*/*-lyrics.cho') ?: []) : 0;
    $metaCount = is_dir($base) ? count(glob($base . '/*/*-metadata.json') ?: []) : 0;
    $audioFileCount = is_dir($base) ? count(array_filter(
        glob($base . '/*/*.*') ?: [],
        fn ($f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), ['mp3','wav','aif','aiff','flac','m4a'], true),
    )) : 0;
    $jsonlRows = is_file(SONGS_AUDIO_JSONL) ? count(file(SONGS_AUDIO_JSONL)) : 0;
    io()->definitionList(
        ['Staging dir' => $base],
        ['.cho files on disk' => $choCount],
        ['-metadata.json files' => $metaCount],
        ['Audio files (symlinks) on disk' => $audioFileCount],
        ['audio.jsonl rows' => $jsonlRows],
    );
    run([...get_console(), 'd:run-sql', "SELECT 'song' as entity, count(*) as cnt FROM song UNION ALL SELECT 'audio', count(*) FROM audio UNION ALL SELECT 'file_asset', count(*) FROM file_asset"]);
}

#[AsTask(namespace: 'songs', name: 'all', description: 'Run the full pipeline: export-csv → split → enrich → ingest → scan-audio → link-audio → ingest-audio')]
function songs_all(bool $rescanAudio = false): void
{
    songs_export_csv();
    songs_split_location();
    songs_split_residency();
    songs_enrich();
    songs_ingest();

    // data/audio.jsonl (+ its .db sidecar) IS the cache for the Songs-Recordings
    // tree: scan once, then let link-audio read the JSONL instead of re-walking the
    // Dropbox mount on every run. Re-scan only when explicitly asked.
    // __DIR__, not the relative path — castor can be invoked from a subdirectory,
    // where is_file('data/audio.jsonl') would miss the cache and re-scan.
    if ($rescanAudio || !is_file(__DIR__ . '/' . SONGS_AUDIO_JSONL)) {
        songs_scan_audio();
    } else {
        io()->note(sprintf('Reusing %s — pass --rescan-audio to re-scan the x10a tree.', SONGS_AUDIO_JSONL));
    }

    songs_link_audio();
    songs_ingest_audio();
    songs_status();
}
