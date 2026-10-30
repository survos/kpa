<?php

namespace App\Services;

use App\Entity\Song;
use App\Entity\Video;
use App\Message\FetchYoutubeChannelMessage;
use App\Message\LoadSongsMessage;
use App\Repository\SongRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use League\Flysystem\DirectoryListing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpWord\Element\FormField;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Table;
use Psr\Log\LoggerInterface;
use Survos\FetchBundle\Contract\PersistentFetcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use League\Flysystem\Filesystem;
use Spatie\Dropbox\Client;
use Spatie\FlysystemDropbox\DropboxAdapter;
use Yectep\PhpSpreadsheetBundle\Factory;

use function Symfony\Component\String\u;

class AppService
{

    public function __construct(private readonly EntityManagerInterface $em,
                                private SongRepository                  $songRepository,
                                private PersistentFetcherInterface      $persistentFetcher,
                                private ValidatorInterface $validator,
                                private readonly LoggerInterface        $logger,
                                #[Autowire('%env(YOUTUBE_API_KEY)%')] private string $youtubeApiKey,
                                #[Autowire('%env(YOUTUBE_CHANNEL)%')] private string $youtubeChannel,

                                #[Autowire('%kernel.project_dir%')] private string $projectDir,
                                private array $songs = [],

    )
    {
    }

    private function getText($elements)
    {
        $text = '';
        foreach ($elements as $element) {
            $elementClass = $element::class;
            switch ($elementClass) {
                case PageBreak::class:
                    $text .= "\f";
                    break;
                case TextBreak::class:
                    $text .= "\n\n";
                    break;
//                    $text = $element->getText();
//                    $text = '??';
                    break;
                case TextRun::class:
                    break;
                case Text::class:
//                    dump($element::class, $element->getText());
                    $text .= "\n" . $element->getText();
                    break;
                case Title::class:
                    $titleElementText = $element->getText();
                    if (is_string($titleElementText)) {
                        $text .= $titleElementText;
                    } else {
                        if ($titleElementText::class == TextRun::class) {
//                            $text .= $this->getText($titleElementText->getElements());
                        } else {
                            dd($titleElementText);
                        }
                    }
                    break;
                case Section::class:
//                    $text .= $this->getText($element->getElements());
                    break;
                case ListItemRun::class:
                case PreserveText::class:
                case Link::class:
                case Image::class:
                case FormField::class:
                case Table::class:
                    break; // ignore for now
                default:
                    dump($elementClass);
            }

            if (method_exists($element, 'getElements')) {
                $text .= $this->getText($element->getElements());
            } else {
                if (method_exists($element, 'getText')) {
//                    $text .= $element->getText();
                } else {
                }
                // $text .= "-no-text\n";
            }
        }
        return $text;

    }

    /**
     * Iterate lyrics JSONL rows as associative arrays:
     *   ['parent' => string, 'file' => string, 'lyrics' => string[]]
     *
     * @return \Generator<int,array{parent:string,file:string,lyrics:array}>
     */
    public function eachLyricsFromJsonl(string $jsonlPath): \Generator
    {
        if (!is_file($jsonlPath) || !is_readable($jsonlPath)) {
            throw new \RuntimeException("Cannot read JSONL at $jsonlPath");
        }
        $fh = new \SplFileObject($jsonlPath, 'r');
        while (!$fh->eof()) {
            $line = trim((string)$fh->fgets());
            if ($line === '') {
                continue;
            }
            /** @var array{parent:string,file:string,lyrics:array} $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            yield $row;
        }
    }


    public function loadLyricsViaDropbox(string $dir)
    {
        $appSecret = '6vhzz4dk8l9e3q2';
        $client = new Client($appSecret);

        $adapter = new DropboxAdapter($client);

        $filesystem = new Filesystem($adapter, ['case_sensitive' => false]);
//        $filesystem->write('/', );
        foreach ($filesystem->listContents('/', $filesystem::LIST_DEEP) as $content) {
            dd($content);
        }
        dd('stopped');


    }
    public function loadLyrics(string $dir, string $jsonlPath): void
    {
        if (!file_exists($dir)) {
            $this->logger->warning("Warning, cannot load lyrics from $dir");
            return;
        }

        // Default output location if not provided
//        $jsonlPath ??= $this->projectDir . '/var/lyrics.jsonl';
        if (!is_dir(dirname($jsonlPath))) {
            @mkdir(dirname($jsonlPath), 0777, true);
        }

        // Open once, write many
        $out = new \SplFileObject($jsonlPath, 'w');

        $finder = new Finder();
        $finder->files()->in($dir)->name('*.doc*');
        $count = 0;

        foreach ($finder as $file) {
            $absoluteFilePath = $file->getRealPath();
            if (!file_exists($absoluteFilePath) || !is_readable($absoluteFilePath)) {
                throw new \RuntimeException($absoluteFilePath . ' is not readable');
            }

            // Required JSONL fields
            $parentDir  = basename($file->getPath());             // immediate parent folder name
            $baseName   = $file->getFilenameWithoutExtension();   // filename w/o extension

            if ($file->getExtension() === 'doc') {
                // Legacy .doc — use catdoc, split on formfeeds, then line-split/trim
                $process = new Process(['catdoc', $absoluteFilePath]);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }

                $raw = str_replace("\r", "\n", $process->getOutput());
                // Some .doc files pack multiple songs separated by formfeed (\f)
                $chunks = preg_split("/\f/u", $raw) ?: [];

                foreach ($chunks as $chunk) {
                    $lines = preg_split("/\R/u", (string)$chunk) ?: [];
                    // Normalize: trim, drop empties
//                    $lines = array_values(array_filter(array_map(fn($s) => trim((string)$s), $lines), fn($s) => $s !== ''));

                    if (!$lines) {
                        continue;
                    }

                    // Write one JSON object per song
                    $record = [
                        'code' => $this->createCode($parentDir . $baseName),
                        'parent'  => $parentDir,
                        'file'    => $file->getBasename(),
                        'lyrics'  => $lines,     // array of strings
                    ];
                }
            } else {
                // .docx and friends — use PhpWord reader via IOFactory
                $reader = IOFactory::createReader();
                try {
                    $phpWord = $reader->load($absoluteFilePath);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('Failed to read "%s": %s', $absoluteFilePath, $e->getMessage()));
                    continue;
                }

                $sections = $phpWord->getSections();
                $text     = $this->getText($sections);
                // Normalize newlines
                $text     = str_replace(["\r\n", "\r"], "\n", $text);
                // Collapse double-blanks produced by layout elements
                $text     = preg_replace("/\n{2,}/", "\n", $text) ?? $text;

                $lines = preg_split("/\n/u", $text) ?: [];
//                dump($lines, $file->getRealPath());
//                $lines = array_values(array_filter(array_map(fn($s) => trim((string)$s), $lines), fn($s) => $s !== ''));

                if ($lines) {
                    $record = [
                        'code' => $this->createCode($parentDir . $baseName),
                        'parent' => $parentDir,
                        'file'   => $baseName,
                        'lyrics' => array_values($lines),
                    ];
//                    $this->logger->warning(json_encode($record, JSON_UNESCAPED_UNICODE, JSON_PRETTY_PRINT) . "\n");
                }
            }
            $this->logger->info(json_encode($record, JSON_PRETTY_PRINT));
            $out->fwrite(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
            $count++;
            if ($count > 5) {
//                break;
            }
        }

        $this->logger->info(sprintf('Wrote lyrics JSONL: %s', realpath($jsonlPath) ?: $jsonlPath));
    }

    public function loadExistingSongs()
    {
        foreach ($this->songRepository->findAll() as $song) {
            $this->songs[$song->code] = $song;
        }
    }

    private function createCode(string $s): string {
        return u($s)->camel()->toString();
    }
    public function loadSongsFromCsv()
    {
        // in2csv kpa-songs.xlsx  > kpa-songs.csv
        $songsCsv = __DIR__ . '/../../data/kpa-songs.csv';
        $reader = Reader::from($songsCsv, 'r');
        $reader->setHeaderOffset(0);
        $records = $reader->getRecords();


//        $q = $this->em->createQuery(sprintf('delete from %s', Song::class));
//        $numDeleted = $q->execute();

        foreach ($records as $idx => $data) {
            if (!$data['Instrumentals']) {
                continue;
            }

            $code = Song::createCode($title = $data['Instrumentals'], $school = $data['school'], $year = $data['year']);
//            $songDate = new \DateTimeImmutable($data['date']);
            $year =  $data['year']??null;

            if (!$song = $this->songs[$code]??null) {
            $song = (new Song($code));
            $this->em->persist($song);
            $this->songs[$code] = $song;
        }
        $song->school = $school;
        $song->publisher = $data['publisher'];
        $song->writers = $data['writer'];
        $song->title = $title;

            if ($data['date'])
//            dd($song->getDate(), $data);

//            if ($song->getDate()) {
//                try {
//                    $song->setYear((int)$song->getDate()->format('Y'));
//                } catch (\Exception) {
//                    $logger->error("Line $idx: Can't set date " . $data['date'] . ' on ' . $song->getTitle());
//                }
//            }
            if ($data['year']) {
                $song->year = (int)$data['year'];
            }

//            $song->setNotes(json_encode($data, JSON_THROW_ON_ERROR));
            if ($idx == 45) {
                // dd($data, $song);
                // break;
            }
        }

        $this->em->flush();
    }

    #[Cache(expires: 'tomorrow', public: true)]
    private function fetchUrl($url)
    {

        $client = HttpClient::create();
        $response = $client->request('GET', $url);
        switch ($response->getStatusCode()) {
            case 403:
                $errors = json_decode($response->getContent(false), FALSE, 512, JSON_THROW_ON_ERROR);
                dd($url, $errors->error);
                break;
            case 200:
                $list = $response->toArray();
                $list = json_decode(json_encode($list, JSON_THROW_ON_ERROR), FALSE, 512, JSON_THROW_ON_ERROR);
                return $list;
        }
    }

    public function testGoogleApi()
    {
        $client = new \Google\Client();
        $client->setApplicationName("Client_Library_Examples");
        $client->setDeveloperKey("YOUR_APP_KEY");

        $service = new \Google\Service\Books($client);
        $query = 'Henry David Thoreau';
        $optParams = [
            'filter' => 'free-ebooks',
        ];
        $results = $service->volumes->listVolumes($query, $optParams);

        foreach ($results->getItems() as $item) {
            echo $item['volumeInfo']['title'], "<br /> \n";
        }
    }

    #[AsMessageHandler]
    public function fetchYoutubeMessageHandler(FetchYoutubeChannelMessage $message): void
    {
        $this->fetchYoutubeChannel($this->youtubeApiKey, $this->youtubeChannel);
    }

    /**
     * @return \App\Entity\Video[]
     */
    public function fetchYoutubeChannel(string $key, string $channelId): array
    {
        // because we check by id, we don't really need this.
//        $q = $this->em->createQuery(sprintf('delete from %s', Video::class));
//        $numDeleted = $q->execute();
        assert($key, "Missing key");
        assert($channelId, "Missing channel id");

        $videos = [];
        $next = '';
        $repo = $this->em->getRepository(Video::class);
        do {

            $url = sprintf("https://www.googleapis.com/youtube/v3/search?part=id,snippet&type=video&maxResults=50&channelId=$channelId&type=video&key=$key&pageToken=$next");

            $result = $this->persistentFetcher->fetch($url);
            $data = json_decode($result->contents ?? '', true) ?? [];

            $next = $data['nextPageToken'] ?? false;
            foreach ($data['items'] as $rawData) {
                $item = (object)$rawData;
                $id = $item->id['videoId'];
                if (!$video = $repo->findOneBy(['youtubeId' => $id])) {
                    $video = new Video();
                    $video->youtubeId = $id;
                    $this->em->persist($video);
                }

                $snippet = (object)$item->snippet;
                $title = $snippet->title;
                // song needs to be school + title, as does the video
                if (!$song = $this->songRepository->findOneBy(['title' => $title])) {
                    $song = (new Song($id));
                    $song->title = $title;
                    // @todo: parse out stuff to get the title
                    $this->em->persist($song);
                    $songs[$id] = $song;
                }
                // default to youtube description
                if (!$song->description) {
                    $song->description = ((object)$item->snippet)->description;
                }
                $this->logger->warning("Adding video to song " . $song->title, ['id' => $video->youtubeId]);
                $song->addVideo($video);
                $video->thumbnails = $snippet->thumbnails;

                $raw = json_decode(json_encode($rawData), true);
                assert($raw, "Raw is null");
//                dd($raw, $snippet);
                $video->title = $snippet->title;
                $video->description = $snippet->description;
                $video->thumbnailUrl = $snippet->thumbnails['default']['url'] ?? null;
                $video->rawData = $raw;

                if ($snippet->publishedAt) {
                    $video->date = new \DateTime($snippet->publishedAt);
                }
                array_push($videos, $video);
            }
            $this->em->flush();
        } while ($next);
        // dd($list);

        return $videos;

        // return $response->getContent();

    }

    private function getSong(string $code): Song
    {
        if (!$song = $this->songs[$code]??null) {
            $song = new Song($code);
            $this->em->persist($song);
            $this->songs[$code] = $song;
        }
        return $song;

    }

    #[AsMessageHandler]
    public function loadSongsHandler(LoadSongsMessage $loadSongsMessage): void
    {
        $this->loadSongs();
        // terrible spot!
        $dir = $this->projectDir . '/../../survos/data/kpa/Lyrics individual songs';
        $this->loadLyrics($dir);
    }

    public function loadSongs(?int $limit = null)
    {
        $this->loadExistingSongs();

        $em = null;
        $excelPath = $this->projectDir . '/data/kpa-songs.xlsx';
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
            $spreadsheet = $reader->load($excelPath);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            throw new \Exception("Problem loading $excelPath");
        }

        /** @var Worksheet $sheet */
        $sheet = $spreadsheet->getActiveSheet();
        $songs = [];
        $lyrics = [];
        $header = [];

        foreach ($sheet->toArray() as $idx=>$row) {
            if ($idx === 0) {
                $header = $row;
            } else {
                $data = array_combine($header, $row);
                if (!$title = $data['Instrumentals']) {
                    continue;
                }
                $school = $data['school'];

                $year = null;
                if ($date = $data['date']) {
                    $year = date_parse($date)['year'];
                }
                $code = Song::createCode($title, $school, year: $year);

                $song = $this->getSong($code);
                $song->school = $school;
                $song->publisher = $data['publisher'];
                $song->year = $year;
                $song->title = $title;
                $song->writers = $data['writer'];
//                if ($data['writer']) dd($data, $song);

                $em = $this->em;
                $logger = $this->logger;
                $em->persist($song);
                if ($data['date']) {
                    try {
                        $song
//                            ->setDate(new \DateTimeImmutable($data['date']))
                        ;
//                        $song->setYear((int)$song->getDate()?->format('Y'));
                    } catch (\Exception) {
                        $logger->error("Line $idx: Can't set date " . $data['date'] . ' on ' . $song->title);
                    }
                }
                if ($data['year']) {
                    $song
                        ->year = (int)$data['year'];
                }

                $song->notes = json_encode($data, JSON_THROW_ON_ERROR);
                if (count($songs) % 500 === 0) {
                    $this->em->flush();
                    $this->logger->warning("Saving, now at $idx");
                }
                array_push($songs, $song);
                // dump($data);

                if ($limit && ($idx >= $limit)) {
                    break;
                }
//                dd($song, $song->title);

            }
            if ($idx == 45) {
                // dd($data, $song);
                // break;
            }
        }

        $this->em->flush();
    }

}
