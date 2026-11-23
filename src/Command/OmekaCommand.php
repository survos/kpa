<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Audio;
use App\Entity\FileAsset;
use App\Entity\Song;
use App\Repository\AudioRepository;
use App\Repository\FileAssetRepository;
use App\Repository\SongRepository;
use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Client\OmekaClientRegistry;
use Survos\OmekaBundle\Model\ResourceTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function array_key_exists;
use function basename;
use function count;
use function filesize;
use function is_array;
use function is_file;
use function is_string;
use function min;
use function sprintf;
use function str_starts_with;
use function trim;

#[AsCommand('app:omeka', 'Sync entities to Omeka')]
final class OmekaCommand
{
    public function __construct(
        private readonly OmekaClientRegistry $omekaClients,
        private readonly SongRepository $songRepository,
        private readonly AudioRepository $audioRepository,
        private readonly FileAssetRepository $fileAssetRepository,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Upload all entities')] bool $all = false,
        #[Option('Upload songs')] bool $songs = false,
        #[Option('Upload recordings')] bool $recordings = false,
        #[Option('Upload file assets')] bool $fileAssets = false,
        #[Option('Song site slug')] string $songSite = 'songs',
        #[Option('Recording site slug')] string $recordingSite = 'recordings',
        #[Option('FileAsset site slug')] string $fileAssetSite = 'fileassets',
        #[Option('Song theme')] string $songTheme = 'default',
        #[Option('Recording theme')] string $recordingTheme = 'default',
        #[Option('FileAsset theme')] string $fileAssetTheme = 'default',
        #[Option('Make file asset site public')] bool $fileAssetPublic = false,
        #[Option('Omeka client name')] string $client = 'remote',
        #[Option('Limit number of records')] ?int $limit = null,
        #[Option('Dry run (no writes)')] bool $dryRun = false,
    ): int {
        if ($all) {
            $songs = true;
            $recordings = true;
            $fileAssets = true;
        }

        if (!$songs && !$recordings && !$fileAssets) {
            $io->warning('No entities selected. Use --songs, --recordings, --file-assets, or --all.');
            return Command::SUCCESS;
        }

        $omeka = $this->omekaClients->get($client);
        if ('' === $omeka->getApiUrl()) {
            $io->warning(sprintf('Omeka client "%s" has no API URL. Set OMEKA_API_URL to sync to Omeka.', $client));
            return Command::SUCCESS;
        }

        $songTemplate = $omeka->getResourceTemplateByLabel('Song');
        if ($songTemplate === null) {
            $io->error('Missing Omeka resource template "Song".');
            return Command::FAILURE;
        }

        $songSiteId = null;
        $recordingSiteId = null;
        $fileAssetSiteId = null;
        if (!$dryRun) {
            $songSiteId = $this->ensureSite($omeka, $songSite, 'Songs', $songTheme, true);
            $recordingSiteId = $this->ensureSite($omeka, $recordingSite, 'Recordings', $recordingTheme, true);
            $fileAssetSiteId = $this->ensureSite($omeka, $fileAssetSite, 'FileAssets', $fileAssetTheme, $fileAssetPublic);
        }

        if ($songs) {
            $status = $this->syncSongs($io, $omeka, $songTemplate, $limit, $dryRun, $songSiteId);
            if ($status !== Command::SUCCESS) {
                return $status;
            }
        }

        if ($recordings) {
            $status = $this->syncRecordings($io, $omeka, $songTemplate, $limit, $dryRun, $recordingSiteId);
            if ($status !== Command::SUCCESS) {
                return $status;
            }
        }

        if ($fileAssets) {
            $status = $this->syncFileAssets($io, $omeka, $limit, $dryRun, $fileAssetSiteId);
            if ($status !== Command::SUCCESS) {
                return $status;
            }
        }

        return Command::SUCCESS;
    }

    private function syncSongs(
        SymfonyStyle $io,
        OmekaClient $omeka,
        ResourceTemplate $songTemplate,
        ?int $limit,
        bool $dryRun,
        ?int $songSiteId,
    ): int
    {
        $songItemSetId = null;
        if (!$dryRun) {
            $songItemSetId = $this->ensureItemSet($omeka, 'Song');
        }

        $queryBuilder = $this->songRepository->createQueryBuilder('song')
            ->orderBy('song.id', 'ASC');
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        $total = $this->songRepository->count([]);
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $progress = $io->createProgressBar($total);
        $progress->start();

        $synced = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($queryBuilder->getQuery()->toIterable() as $song) {
            if (!$song instanceof Song) {
                $progress->advance();
                continue;
            }

            $title = $song->title !== null ? trim($song->title) : '';
            if ($title === '') {
                $skipped++;
                $progress->advance();
                continue;
            }

            $properties = $this->buildSongProperties($song);
            if (!isset($properties['dcterms:identifier'])) {
                $skipped++;
                $progress->advance();
                continue;
            }

            $existing = $omeka->filterItemsByProperty(
                property: 'dcterms:identifier',
                value: (string) $properties['dcterms:identifier'],
                type: 'eq',
                page: 1,
                resourceTemplateId: $songTemplate->id,
            );

            $itemId = null;
            if (count($existing->results) > 0) {
                $itemId = $existing->results[0]['o:id'] ?? null;
            }

            if ($dryRun) {
                $io->text(sprintf('song %s -> %s', $song->code, $itemId ? 'update' : 'create'));
                $synced++;
                $progress->advance();
                continue;
            }

            if ($itemId !== null) {
                $updatePayload = $omeka->buildPayload($properties, $songTemplate->id);
                $omeka->updateItem((int) $itemId, $updatePayload);
                if ($songSiteId !== null) {
                    $omeka->updateItemSites((int) $itemId, [$songSiteId]);
                }
                $updated++;
            } else {
                $omeka->createItem(
                    properties: $properties,
                    templateId: $songTemplate->id,
                    itemSetId: $songItemSetId,
                    siteIds: $songSiteId !== null ? [$songSiteId] : null,
                );
                $created++;
            }

            $synced++;
            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Songs synced: %d (created %d, updated %d, skipped %d)',
            $synced,
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function syncRecordings(
        SymfonyStyle $io,
        OmekaClient $omeka,
        ResourceTemplate $songTemplate,
        ?int $limit,
        bool $dryRun,
        ?int $recordingSiteId,
    ): int
    {
        $recordingTemplate = $omeka->getResourceTemplateByLabel('Recording');
        if ($recordingTemplate === null) {
            $io->error('Missing Omeka resource template "Recording".');
            return Command::FAILURE;
        }

        $recordingItemSetId = null;
        if (!$dryRun) {
            $recordingItemSetId = $this->ensureItemSet($omeka, 'Audio');
        }

        $queryBuilder = $this->audioRepository->createQueryBuilder('audio')
            ->orderBy('audio.id', 'ASC');
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        $total = $this->audioRepository->count([]);
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $progress = $io->createProgressBar($total);
        $progress->start();

        $synced = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($queryBuilder->getQuery()->toIterable() as $audio) {
            if (!$audio instanceof Audio) {
                $progress->advance();
                continue;
            }

            $songItemId = $this->findSongItemId($omeka, $songTemplate, $audio->song);
            if ($songItemId === null) {
                $skipped++;
                $progress->advance();
                continue;
            }

            $mediaPath = $this->resolvePath($audio->fileAsset->path ?? null);
            if ($mediaPath === null) {
                $skipped++;
                $progress->advance();
                continue;
            }

            if ($io->isVerbose()) {
                $size = is_file($mediaPath) ? filesize($mediaPath) : null;
                $io->text(sprintf(
                    'recording file: %s (%s bytes)',
                    basename($mediaPath),
                    $size !== null ? (string) $size : 'unknown'
                ));
            }

            $properties = $this->buildRecordingProperties($audio, $songItemId);
            $title = $properties['dcterms:title'] ?? '';
            if ($title === '') {
                $skipped++;
                $progress->advance();
                continue;
            }

            $existing = $omeka->filterItemsByProperty(
                property: 'dcterms:title',
                value: (string) $title,
                type: 'eq',
                page: 1,
                resourceTemplateId: $recordingTemplate->id,
            );

            $itemId = null;
            if (count($existing->results) > 0) {
                $itemId = $existing->results[0]['o:id'] ?? null;
            }

            if ($dryRun) {
                $io->text(sprintf('recording %s -> %s', $audio->id ?? 'n/a', $itemId ? 'update' : 'create'));
                $synced++;
                $progress->advance();
                continue;
            }

            if ($itemId !== null) {
                $updatePayload = $omeka->buildPayload($properties, $recordingTemplate->id);
                $omeka->updateItem((int) $itemId, $updatePayload);
                if ($recordingSiteId !== null) {
                    $omeka->updateItemSites((int) $itemId, [$recordingSiteId]);
                }
                $updated++;
            } else {
                $omeka->createItem(
                    properties: $properties,
                    templateId: $recordingTemplate->id,
                    itemSetId: $recordingItemSetId,
                    siteIds: $recordingSiteId !== null ? [$recordingSiteId] : null,
                    mediaFiles: [$mediaPath],
                );
                $created++;
            }

            $synced++;
            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Recordings synced: %d (created %d, updated %d, skipped %d)',
            $synced,
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function syncFileAssets(
        SymfonyStyle $io,
        OmekaClient $omeka,
        ?int $limit,
        bool $dryRun,
        ?int $fileAssetSiteId,
    ): int
    {
        $fileAssetTemplate = $omeka->getResourceTemplateByLabel('FileAsset');
        if ($fileAssetTemplate === null) {
            $io->error('Missing Omeka resource template "FileAsset".');
            return Command::FAILURE;
        }

        $fileAssetItemSetId = null;
        if (!$dryRun) {
            $fileAssetItemSetId = $this->ensureItemSet($omeka, 'FileAsset');
        }

        $queryBuilder = $this->fileAssetRepository->createQueryBuilder('fileAsset')
            ->orderBy('fileAsset.id', 'ASC');
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        $total = $this->fileAssetRepository->count([]);
        if ($limit !== null) {
            $total = min($total, $limit);
        }

        $progress = $io->createProgressBar($total);
        $progress->start();

        $synced = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($queryBuilder->getQuery()->toIterable() as $fileAsset) {
            if (!$fileAsset instanceof FileAsset) {
                $progress->advance();
                continue;
            }

            $properties = $this->buildFileAssetProperties($fileAsset);
            $identifier = $properties['dcterms:identifier'] ?? '';
            if ($identifier === '') {
                $skipped++;
                $progress->advance();
                continue;
            }

            $existing = $omeka->filterItemsByProperty(
                property: 'dcterms:identifier',
                value: (string) $identifier,
                type: 'eq',
                page: 1,
                resourceTemplateId: $fileAssetTemplate->id,
            );

            $itemId = null;
            if (count($existing->results) > 0) {
                $itemId = $existing->results[0]['o:id'] ?? null;
            }

            if ($dryRun) {
                $io->text(sprintf('fileasset %s -> %s', $fileAsset->id ?? 'n/a', $itemId ? 'update' : 'create'));
                $synced++;
                $progress->advance();
                continue;
            }

            $mediaPath = null;
            if ($fileAsset->isReadable) {
                $mediaPath = $this->resolvePath($fileAsset->path ?? null);
            }

            if ($itemId !== null) {
                $updatePayload = $omeka->buildPayload($properties, $fileAssetTemplate->id);
                $omeka->updateItem((int) $itemId, $updatePayload);
                if ($fileAssetSiteId !== null) {
                    $omeka->updateItemSites((int) $itemId, [$fileAssetSiteId]);
                }
                $updated++;
            } else {
                $omeka->createItem(
                    properties: $properties,
                    templateId: $fileAssetTemplate->id,
                    itemSetId: $fileAssetItemSetId,
                    siteIds: $fileAssetSiteId !== null ? [$fileAssetSiteId] : null,
                    mediaFiles: $mediaPath !== null ? [$mediaPath] : null,
                );
                $created++;
            }

            $synced++;
            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'FileAssets synced: %d (created %d, updated %d, skipped %d)',
            $synced,
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function buildSongProperties(Song $song): array
    {
        $properties = [
            'dcterms:title' => $song->title,
            'dcterms:identifier' => $song->code,
        ];

        $creator = $this->resolveSongCreator($song);
        if ($creator !== null) {
            $properties['dcterms:creator'] = $creator;
        }
        $this->addLiteral($properties, 'dcterms:description', $song->description);
        $this->addLiteral($properties, 'dcterms:publisher', $song->publisher);

        if ($song->date !== null) {
            $properties['dcterms:created'] = $song->date->format('Y-m-d');
        } elseif ($song->year !== null) {
            $properties['dcterms:created'] = (string) $song->year;
        }

        if ($song->lyrics !== null && trim($song->lyrics) !== '') {
            $description = $properties['dcterms:description'] ?? null;
            if ($description === null) {
                $properties['dcterms:description'] = $song->lyrics;
            } elseif (is_array($description)) {
                $description[] = $song->lyrics;
                $properties['dcterms:description'] = $description;
            } else {
                $properties['dcterms:description'] = [$description, $song->lyrics];
            }
        }

        return $properties;
    }

    private function buildRecordingProperties(Audio $audio, int $songItemId): array
    {
        $properties = [
            'dcterms:title' => $audio->title,
            'dcterms:relation' => [
                'value' => $songItemId,
                'type' => 'resource',
            ],
        ];

        $this->addLiteral($properties, 'dcterms:format', $audio->format);

        if ($audio->fileAsset->duration !== null) {
            $properties['dcterms:extent'] = sprintf('%.2f', $audio->fileAsset->duration);
        } elseif ($audio->size !== null) {
            $properties['dcterms:extent'] = (string) $audio->size;
        }

        return $properties;
    }

    private function buildFileAssetProperties(FileAsset $fileAsset): array
    {
        $properties = [
            'dcterms:title' => $fileAsset->filename,
            'dcterms:identifier' => $fileAsset->path,
        ];

        if ($fileAsset->relativePath !== '') {
            $properties['dcterms:description'] = $fileAsset->relativePath;
        }

        if ($fileAsset->type !== '') {
            $properties['dcterms:subject'] = $fileAsset->type;
        } elseif ($fileAsset->extension !== '') {
            $properties['dcterms:subject'] = $fileAsset->extension;
        }

        return $properties;
    }

    private function resolveSongCreator(Song $song): ?string
    {
        $writer = $this->normalizeValue($song->writers);
        if ($writer !== null) {
            return $writer;
        }

        $audio = $this->audioRepository->findOneBy(['song' => $song], ['id' => 'ASC']);
        if (!$audio instanceof Audio) {
            return null;
        }

        $tags = $audio->fileAsset->probedData['summary']['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }

        foreach (['artist', 'composer', 'album_artist', 'author'] as $key) {
            if (!array_key_exists($key, $tags)) {
                continue;
            }
            $value = $this->normalizeValue($tags[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function ensureItemSet(OmekaClient $omeka, string $label): ?int
    {
        $result = $omeka->filterItemSetsByProperty('dcterms:title', $label, 'eq', 1);
        if (count($result->results) > 0) {
            $existingId = $result->results[0]['o:id'] ?? null;
            return is_string($existingId) || is_int($existingId) ? (int) $existingId : null;
        }

        $created = $omeka->createItemSet([
            'dcterms:title' => $label,
        ]);

        $id = $created['o:id'] ?? null;
        return is_string($id) || is_int($id) ? (int) $id : null;
    }

    private function ensureSite(
        OmekaClient $omeka,
        string $slug,
        string $title,
        string $theme,
        bool $isPublic,
    ): ?int {
        $sites = $omeka->getSites();
        foreach ($sites as $site) {
            $siteSlug = $site['o:slug'] ?? null;
            if (!is_string($siteSlug)) {
                continue;
            }
            if ($siteSlug === $slug) {
                $id = $site['o:id'] ?? null;
                return is_string($id) || is_int($id) ? (int) $id : null;
            }
        }

        $created = $omeka->createSite($slug, $title, $theme, $isPublic);
        $id = $created['o:id'] ?? null;
        return is_string($id) || is_int($id) ? (int) $id : null;
    }

    private function normalizeValue(string|array|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $candidate = $this->normalizeValue(is_string($item) ? $item : null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function findSongItemId(
        OmekaClient $omeka,
        ResourceTemplate $songTemplate,
        Song $song,
    ): ?int {
        $identifier = $song->code;
        if (trim($identifier) === '') {
            return null;
        }

        $result = $omeka->filterItemsByProperty(
            property: 'dcterms:identifier',
            value: $identifier,
            type: 'eq',
            page: 1,
            resourceTemplateId: $songTemplate->id,
        );

        if (count($result->results) === 0) {
            return null;
        }

        $itemId = $result->results[0]['o:id'] ?? null;
        return is_string($itemId) || is_int($itemId) ? (int) $itemId : null;
    }

    private function resolvePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectDir . '/' . ltrim($path, '/');
    }

    private function addLiteral(array &$properties, string $term, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        $properties[$term] = $trimmed;
    }
}
