<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SongEmbeddingService;
use App\Entity\Song;

use Survos\SearchBundle\Search\Searcher;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SongSearchController extends AbstractController
{
    public function __construct(private SearchProvider $searches, private Searcher $searcher) {}

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function faceted(): Response
    {
        return $this->render('song/faceted_search.html.twig');
    }

    #[Route('/songs/search', name: 'song_search', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $q = trim($request->query->getString('q'));
        $mode = $request->query->getString('mode', 'hybrid');
        if (!in_array($mode, ['lexical', 'semantic', 'hybrid', 'compare'], true) || mb_strlen($q) > 500) {
            throw $this->createNotFoundException('Invalid search mode or query length.');
        }
        $results = [];
        $excerpts = [];
        foreach ($mode === 'compare' ? ['lexical', 'semantic', 'hybrid'] : [$mode] as $name) {
            $search = $this->searches->getSearch('app_song')->create(['retrievalMode' => $name === 'semantic' ? 'vector' : $name]);
            $query = $search->createQuery()->setQueryString($q)->setActiveSort(null)->setActiveHitsPerPage(12);
            $results[$name] = $this->searcher->search($query, $search);
            foreach ($results[$name]->getHits() as $hit) {
                $song = $hit->getData();
                $id = $song instanceof Song ? $song->id : $song['id'];
                $text = $song instanceof Song ? ($song->lyrics ?? $song->description ?? '') : ($song['lyrics'] ?? $song['description'] ?? '');
                $excerpts[$id] = SongEmbeddingService::cleanLyrics($text);
            }
        }
        return $this->render('song/search.html.twig', compact('q', 'mode', 'results', 'excerpts'));
    }
}
