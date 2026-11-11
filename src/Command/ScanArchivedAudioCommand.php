<?php

declare(strict_types=1);

namespace App\Command;

use Aws\S3\S3Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/** Cache the metadata already produced by the original probe/upload pipeline. */
final class ScanArchivedAudioCommand
{
    public function __construct(private S3Client $s3, private Filesystem $filesystem) {}

    #[AsCommand('app:scan-s3-audio', 'Cache KPA S3 recording metadata without downloading MP3s')]
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Persistent metadata cache directory')] string $cache = 'var/archive-audio',
    ): int {
        $this->filesystem->mkdir($cache.'/sidecars');
        $objects = [];
        foreach ($this->s3->getPaginator('ListObjectsV2', ['Bucket' => 'kpa-songs']) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $objects[$object['Key']] = ['size' => $object['Size'], 'etag' => $object['ETag']];
            }
        }
        $manifest = [];
        foreach ($objects as $key => $facts) {
            if (!str_ends_with($key, '.json') || !isset($objects[substr($key, 0, -5).'.mp3'])) { continue; }
            $path = $cache.'/sidecars/'.hash('sha256', $key.$facts['etag']).'.json';
            if (!is_file($path)) {
                $body = (string) $this->s3->getObject(['Bucket' => 'kpa-songs', 'Key' => $key])['Body'];
                json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $this->filesystem->dumpFile($path, $body);
            }
            $row = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (($row['archive']['objectKey'] ?? null) !== substr($key, 0, -5).'.mp3') {
                throw new \RuntimeException('Sidecar object key mismatch: '.$key);
            }
            $manifest[$key] = $row;
        }
        $this->filesystem->dumpFile($cache.'/inventory.json', json_encode($objects, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->filesystem->dumpFile($cache.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $io->success(sprintf('Cached %d recording sidecars in %s/manifest.json. No MP3s downloaded.', count($manifest), $cache));
        return 0;
    }
}
