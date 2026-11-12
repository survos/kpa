<?php

namespace App\Controller;

use App\Entity\Audio;
use Survos\FieldBundle\Attribute\ControllerMeta;
use Survos\FieldBundle\Attribute\RouteMeta;
use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[Route(path: '/audio', priority: 10000)]
#[ControllerMeta(entity: Audio::class, audience: Audience::Public, tags: ['catalog', 'media'])]
class AudioController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    ) {
    }
    #[Route('/', name: 'audio_index', methods: [Request::METHOD_GET])]
    #[Template('audio/index.html.twig')]
    #[RouteMeta(
        description: 'Browse and search audio recordings through the Doctrine-backed API grid.',
        purpose: Purpose::List,
        sitemap: true,
        changefreq: 'weekly',
        priority: 0.7
    )]
    public function index(): Response|array
    {
        return [
            'class' => Audio::class,
        ];
    }

    #[Route('/{id}', name: 'audio_show', options: ['expose' => true], methods: [Request::METHOD_GET])]
    #[Template('audio/show.html.twig')]
    public function show(Audio $audio): Response|array
    {
        $segments = [];
        if (is_array($audio->lyricsJson)) {
            $segments = $this->loadWhisperSegmentsFromArray($audio->lyricsJson);
        } else {
            $jsonPath = $this->resolveJsonPath($audio);
            if ($jsonPath && is_file($jsonPath)) {
                $segments = $this->loadWhisperSegments($jsonPath);
            }
        }

        return [
            'audio' => $audio,
            'segments' => $segments,
        ];
    }

    #[Route('/{id}/musicxml', name: 'audio_musicxml', methods: [Request::METHOD_GET])]
    public function musicXml(Audio $audio): Response
    {
        if (!$audio->musicXml) {
            return new Response('MusicXML not available', Response::HTTP_NOT_FOUND);
        }

        return new Response(
            $audio->musicXml,
            Response::HTTP_OK,
            ['Content-Type' => 'application/vnd.recordare.musicxml+xml; charset=utf-8']
        );
    }

    #[Route('/{id}/file', name: 'audio_file', methods: [Request::METHOD_GET])]
    public function audioFile(Audio $audio): Response
    {
        // Uploaded → s3 holds the full public URL; hand it to the browser (native Range/seeking).
        $url = $audio->fileAsset->getPlaybackUrl();
        if ($url) {
            return new RedirectResponse($url);
        }

        // Not yet uploaded → serve from the local drive (dev fallback).
        $path = $this->resolvePath($audio->fileAsset->path ?? null);
        if (!$path || !is_file($path)) {
            return new Response('Audio file not available', Response::HTTP_NOT_FOUND);
        }

        return new BinaryFileResponse($path);
    }

    private function resolveJsonPath(Audio $audio): ?string
    {
        if (!$audio->id) {
            return null;
        }
        $path = $this->projectDir . '/var/whisper/audio_' . $audio->id . '.json';
        $resolved = realpath($path);
        return $resolved ?: $path;
    }

    private function loadWhisperSegments(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        return $this->loadWhisperSegmentsFromArray($data);
    }

    private function loadWhisperSegmentsFromArray(array $data): array
    {
        $segments = $data['segments'] ?? $data['transcription'] ?? [];
        $result = [];

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            if (isset($segment['start']) || isset($segment['end'])) {
                $start = (float) ($segment['start'] ?? 0.0);
                $end = (float) ($segment['end'] ?? 0.0);
            } else {
                $offsets = $segment['offsets'] ?? [];
                $start = ((float) ($offsets['from'] ?? 0.0)) / 1000.0;
                $end = ((float) ($offsets['to'] ?? 0.0)) / 1000.0;
            }

            $result[] = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];
        }

        return $result;
    }

    private function resolvePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $absolute = $this->projectDir . '/' . ltrim($path, '/');
        $resolved = realpath($absolute);
        return $resolved ?: $absolute;
    }
}
