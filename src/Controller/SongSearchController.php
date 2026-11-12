<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SongSearchController extends AbstractController
{
    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function faceted(): Response
    {
        return $this->render('song/faceted_search.html.twig');
    }

    #[Route('/songs/search', name: 'song_search', methods: ['GET'])]
    public function legacy(Request $request): Response
    {
        return $this->redirectToRoute('app_search', ['q' => $request->query->getString('q')]);
    }
}
