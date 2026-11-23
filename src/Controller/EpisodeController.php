<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The podcast: every episode app:episode:publish wrote to the episodes directory
 * (one folder per episode: episode.json, <slug>.mp3, <slug>.vtt), its page and the RSS feed.
 */
final class EpisodeController extends AbstractController
{
    private const SLUG = '[a-z0-9][a-z0-9-]{1,80}';

    public function __construct(
        #[Autowire(env: 'resolve:KPA_EPISODES_DIR')] private readonly string $dir,
        #[Autowire(param: 'kpa.episodes')] private readonly array $show,
    ) {
    }

    #[Route('/episodes', name: 'episode_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('episode/index.html.twig', ['episodes' => $this->episodes(), 'show' => $this->show]);
    }

    #[Route('/episodes/feed.xml', name: 'episode_feed', methods: ['GET'], priority: 10)]
    public function feed(): Response
    {
        $response = $this->render('episode/feed.xml.twig', ['episodes' => $this->episodes(), 'show' => $this->show]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=utf-8');

        return $response;
    }

    #[Route('/episodes/{slug}.mp3', name: 'episode_mp3', requirements: ['slug' => self::SLUG], methods: ['GET', 'HEAD'], priority: 5)]
    public function mp3(string $slug): BinaryFileResponse
    {
        return $this->file($this->path($slug, $slug.'.mp3'), $slug.'.mp3', 'inline');
    }

    #[Route('/episodes/{slug}.vtt', name: 'episode_vtt', requirements: ['slug' => self::SLUG], methods: ['GET'], priority: 5)]
    public function vtt(string $slug): BinaryFileResponse
    {
        $response = new BinaryFileResponse($this->path($slug, $slug.'.vtt'));
        $response->headers->set('Content-Type', 'text/vtt; charset=utf-8');

        return $response;
    }

    #[Route('/episodes/{slug}', name: 'episode_show', requirements: ['slug' => self::SLUG], methods: ['GET'])]
    public function show(string $slug): Response
    {
        return $this->render('episode/show.html.twig', ['episode' => $this->episode($slug), 'show' => $this->show]);
    }

    /** @return list<array<string, mixed>> newest first */
    private function episodes(): array
    {
        $episodes = [];
        foreach (glob($this->dir.'/*/episode.json') ?: [] as $file) {
            $episodes[] = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        }
        usort($episodes, static fn (array $a, array $b) => $b['published'] <=> $a['published']);

        return $episodes;
    }

    /** @return array<string, mixed> */
    private function episode(string $slug): array
    {
        return json_decode((string) file_get_contents($this->path($slug, 'episode.json')), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function path(string $slug, string $file): string
    {
        $path = $this->dir.'/'.$slug.'/'.$file;
        if (!preg_match('/^'.self::SLUG.'$/', $slug) || !is_file($path)) {
            throw new NotFoundHttpException('No such episode.');
        }

        return $path;
    }
}
