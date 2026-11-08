<?php

namespace App\Controller;

use App\Entity\Song;
use App\Services\AppService;
use App\Services\DocxConversion;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;
use App\Repository\SongRepository;
use Survos\FieldBundle\Enum\Purpose;
use Survos\FieldBundle\Registry\EntityMetaRegistry;
use Survos\FieldBundle\Registry\RouteMetaRegistry;
use Survos\WordpressBundle\Client\WordpressClientInterface;
use Survos\WordpressBundle\Exception\WordpressExceptionInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use App\Entity\Lyrics;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;

class AppController extends AbstractController
{
    public function __construct(
        private AppService $appService,
                                private readonly Environment $twig,
                                private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private string $env,
    )
    {
    }

    #[Route(path: '/', name: 'app_homepage', methods: ['GET'])]
    public function homepage(
        EntityMetaRegistry $entityMetaRegistry,
        RouteMetaRegistry $routeMetaRegistry,
        #[Autowire('%survos_meili.chat%')] array $chatConfig = [],
//    #[Autowire('%kpa.version%')] ?string $applicationVersion = null, // was in bizkit_version
    )
    {
        $user = $this->getUser();
        $workspaces = $chatConfig['workspaces'] ?? [];
        $defaultWorkspace = $workspaces !== [] ? array_key_first($workspaces) : 'default';
        $entities = [];

        foreach ($entityMetaRegistry->getByGroup('Catalog') as $descriptor) {
            $listRoute = $routeMetaRegistry->forEntityPurpose($descriptor->class, Purpose::List);
            if ($listRoute === null) {
                continue;
            }

            $entities[] = [
                'code' => $descriptor->code,
                'class' => $descriptor->class,
                'label' => $descriptor->label,
                'icon' => $descriptor->icon,
                'description' => $descriptor->description,
                'count' => $this->em->getRepository($descriptor->class)->count([]),
                'gridRoute' => $listRoute->name,
                'meiliIndex' => strtolower($descriptor->getShortName()),
                'order' => $descriptor->order,
            ];
        }

        usort($entities, static fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $this->render('app/homepage.html.twig', [
            'user' => $user,
            'dashboardEntities' => $entities,
            'defaultWorkspace' => $defaultWorkspace,
        ]);
    }

    private function loadLyrics($songs)
    {
        $this->appService->loadLyricsViaDropbox('/');
        // @todo: fetch lyrics from Dropbox
    }

    private function loadBestFriendsLyrics($songs)
    {


        $file = '../bf-lyrics.docx';
        if (!file_exists($file)) {
            throw new \Exception("File $file does not exist");
        }
        $converter = new DocxConversion($file);
        $text = $converter->convertToText();

        /** @var Song $currentSong */
        $currentSong = null;
        $songLyrics = '';
        foreach (explode("\n", (string) $text) as $s) {
            $s = trim($s);

            // total hack, but too lazy to do it right
            /** @var Song $song */
            foreach ($songs as $song) {
                if ($s == $song->title) {
                    // found a song!
                    if ($songLyrics && $currentSong) {
                        $currentSong->lyrics = $songLyrics;
                        $songLyrics = '';
                    }
                    $currentSong = $song;
                } else {
                    //
                }
            }
            if ($currentSong) {
                $songLyrics .= $s;
            }
        }
        return $text;


    }

    private function createPage(Song $song)
    {
        $content = $this->twig->render("song.html.twig", [
            'song' => $song,
        ]);
    }

    private function getSongs()
    {
        return $this->em->getRepository(Song::class)->findAll();
    }

    #[Route(path: '/song_credits', name: 'app_credits_page')]
    public function credits()
    {
        return $this->render('app/song_credits.html.twig', [
            'songs' => $this->getSongs()
        ]);
    }


    /**
     * WordPress publishing status: which songs already have a page, and whether the site is
     * reachable with the credentials we hold.
     *
     * Read-only on purpose. Publishing writes to a live public website, so it lives in
     * `kpa:song:publish` (see App\Services\WordpressService) rather than behind a GET route.
     */
    #[Route(path: '/publish', name: 'app_publish')]
    #[Template('app/publish.html.twig')]
    public function publish(
        WordpressClientInterface $wordpress,
        SongRepository $songRepository,
    ): array {
        $error = null;
        $index = [];
        try {
            $index = $wordpress->index();
        } catch (WordpressExceptionInterface $e) {
            $error = $e->getMessage();
        }

        return [
            'siteUrl' => $wordpress->siteUrl(),
            'authenticated' => $wordpress->isAuthenticated(),
            'index' => $index,
            'error' => $error,
            'totalSongs' => $songRepository->count([]),
            'unpublished' => $songRepository->count(['wordpressPageId' => null]),
            'songs' => $songRepository->findBy([], ['id' => 'ASC'], 50),
        ];
    }

    #[Route(path: '/load-kpa-channel', name: 'app_load_youtube_channel')]
    public function loadYoutubeChannel(EntityManagerInterface $em, LoggerInterface $logger, ParameterBagInterface $bag, AppService $appService)
    {
        $key = $bag->get('youtube_api_key');
        $channel = $bag->get('youtube_channel');
        $videos = $appService->fetchYoutubeChannel($key, $channel);
        return $this->redirectToRoute('video_index');
    }

    #[Route(path: '/load-kpa-songs', name: 'app_load_songs')]
    public function loadSongs(AppService $appService)
    {
        $appService->loadSongs();
        return $this->redirectToRoute('song_index');
        return $this->render('app/index.html.twig', [
            'lyrics' => $lyrics,
            'songs' => $songs
        ]);
    }


    #[Route(path: '/load-lyrics-from-files', name: 'app_load_lyrics')]
    public function index(AppService $appService, EntityManagerInterface $em)
    {
        $this->appService->loadLyricsViaDropbox('/');

        $dir = __DIR__ . '/../../data/lyrics';
        $appService->loadLyrics($dir);
        return $this->redirectToRoute('song_index', ['lyrics_only' => true]);
    }

    #[Route(path: '/load-best-friends', name: 'app_load_best_friends')]
    public function bestFriends(EntityManagerInterface $em)
    {
        /** @var Xls $readerXlsx */
        $readerXlsx  = $this->spreadsheet->createReader('Xlsx');
        /** @var Spreadsheet $spreadsheet */
        try {
            $spreadsheet = $readerXlsx->load('/var/www/kpa/best-friends-credits.xlsx');
        } catch (\Exception $exception) {
            dd($exception);
        }
        /** @var Worksheet $sheet */
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($sheet->toArray() as $idx=>$row) {
            if ($idx === 0) {
                $header = $row;
            } else {
                $data = array_combine($header, $row);
                $title = $data['Song Title'];
                if (!$title) {
                    continue;
                }
                // look for the title
//                dump($data);
                if (!$song = $em->getRepository(Song::class)->findOneBy(['title' => $title])) {
                    $school = $data['school'] ?? $data['School'] ?? null;
                    $year = $data['year'] ?? $data['Year'] ?? null;
                    $code = Song::createCode($title, $school, $year);
                    $song = new Song($code);
                    $song->title = $title;
                    $em->persist($song);
                }
                $song->writers = $data['Writers'];
                $song->musicians = $data['Musicians'];
                $song->recordingCredits = $data['Recording Credits'];
                $song->featuredArtist = $data['Featured Artist'];
                $this->createPage($song);
                $em->flush();
            }
        }
        $songs = $this->getSongs();
        $lyrics = $this->loadBestFriendsLyrics($songs);
        // dd($spreadsheet);
        return $this->render('app/index.html.twig', [
            'controller_name' => 'AppController',
            'lyrics' => $lyrics,
            'songs' => $songs
        ]);
    }

    #[AdminRoute(
        path: '/lyrics/{code}/music',
        name: 'app_lyrics_music'
    )]
    public function lyricsMusic(Lyrics $lyrics, AdminContext $adminContext)
    {
        return $this->render('app/lyrics_music.html.twig', [
            'lyrics' => $lyrics,
            'adminContext' => $adminContext
        ]);
    }
}
