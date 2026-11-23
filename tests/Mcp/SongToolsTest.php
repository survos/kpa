<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The MCP server as a client sees it: JSON-RPC over HTTP at /mcp, against a small catalog in
 * SQLite, with a bag-of-words embedder (FakeEmbedder) in place of Ollama.
 */
final class SongToolsTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $session = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $embedder = new FakeEmbedder();
        $songs = [
            ['test-snow-day', 'Snow Day', 'Test School', 2020, "{title: Snow Day}\n{start_of_chorus}\nSnow day, snow day, no school today\nOld Man Winter came to play\n{end_of_chorus}"],
            ['test-ocean', 'The Ocean Song', 'Seaside Elementary', 2018, "{title: The Ocean Song}\nWaves and whales and the deep blue sea"],
            ['test-thanks', 'Give Thanks', 'Test School', 2016, "{title: Give Thanks}\nWe give thanks for family &amp; friends"],
        ];
        foreach ($songs as [$id, $title, $school, $year, $lyrics]) {
            $em->getConnection()->insert('song', [
                'id' => $id, 'title' => $title, 'school' => $school, 'year' => $year, 'lyrics' => $lyrics, 'marking' => 'new',
                'embedding' => json_encode($embedder->embed($title.' '.$lyrics), \JSON_THROW_ON_ERROR),
                'embedding_fingerprint' => hash('sha256', $id),
            ]);
        }
    }

    public function testListsTheCatalogTools(): void
    {
        $tools = array_column($this->rpc('tools/list')['tools'], null, 'name');

        foreach (['find_songs', 'get_song', 'songs_by_school', 'catalog_stats', 'search_lyrics', 'list_episodes', 'get_episode'] as $name) {
            self::assertArrayHasKey($name, $tools);
            self::assertTrue($tools[$name]['annotations']['readOnlyHint'], $name.' is read-only');
        }
        self::assertSame(['question'], $tools['find_songs']['inputSchema']['required']);
    }

    public function testFindsSongsByMeaningAndFilters(): void
    {
        $hits = $this->tool('find_songs', ['question' => 'whales in the deep sea', 'limit' => 2]);
        self::assertSame('test-ocean', $hits[0]['id']);
        self::assertStringEndsWith('/song/test-ocean/', $hits[0]['url']);

        $hits = $this->tool('find_songs', ['question' => 'whales in the deep sea', 'school' => 'Test School']);
        self::assertNotContains('test-ocean', array_column($hits, 'id'));
        self::assertSame([], $this->tool('find_songs', ['question' => 'snow', 'withRecording' => true]), 'no test song has a recording');
    }

    public function testSearchesLyricsForExactWordsIgnoringCaseAndPunctuation(): void
    {
        $hits = $this->tool('search_lyrics', ['phrase' => 'old man WINTER']);
        self::assertSame(['test-snow-day'], array_column($hits, 'id'));
        self::assertSame(['Old Man Winter came to play'], $hits[0]['matches']);

        $hits = $this->tool('search_lyrics', ['phrase' => 'friends']);
        self::assertSame(['We give thanks for family & friends'], $hits[0]['matches'], 'entities are decoded');

        self::assertSame('Give a phrase of at least three letters.', $this->toolError('search_lyrics', ['phrase' => 'ab']));
    }

    public function testReadsASongAndCountsTheCatalog(): void
    {
        $song = $this->tool('get_song', ['id' => 'test-snow-day']);
        self::assertSame('Snow Day', $song['title']);
        self::assertStringContainsString('Snow day, snow day', $song['chorus']);
        self::assertStringNotContainsString('{', $song['lyrics'], 'no ChordPro directives');
        self::assertStringContainsString('No song with id "nope"', $this->toolError('get_song', ['id' => 'nope']));

        self::assertSame(['Give Thanks', 'Snow Day'], array_column($this->tool('songs_by_school', ['school' => 'test']), 'title'), 'oldest first');
        $stats = $this->tool('catalog_stats');
        self::assertSame(3, $stats['songs']);
        self::assertSame(0, $stats['withRecordings']);
    }

    public function testListsAndReadsPodcastEpisodes(): void
    {
        $episodes = $this->tool('list_episodes');
        self::assertSame('sample', $episodes[0]['slug']);
        self::assertSame(['Snow Day (Test School, 2020)'], $episodes[0]['songs']);
        self::assertMatchesRegularExpression('~/episodes/sample\.mp3\?v=\d+$~', $episodes[0]['audio']);

        $episode = $this->tool('get_episode', ['slug' => 'sample']);
        self::assertSame("PaulBot: Here is a song about snow.\n[Music: \"Snow Day\", Test School, 2020]", $episode['transcript']);
        self::assertSame(5.5, $episode['chapters'][1]['start']);
        self::assertStringContainsString('No episode "nope"', $this->toolError('get_episode', ['slug' => 'nope']));
    }

    public function testTheConnectPageNamesTheEndpointAndEveryTool(): void
    {
        $this->client->request('GET', '/ask/connect');
        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('https://kpa.survos.com/mcp', $page);
        self::assertStringContainsString('claude mcp add --transport http kpa https://kpa.survos.com/mcp', $page);
        foreach (array_column($this->rpc('tools/list')['tools'], 'name') as $tool) {
            if (!in_array($tool, ['app_recordings_candidates', 'agent_calls'], true)) {
                self::assertStringContainsString('<code>'.$tool.'</code>', $page, 'the page documents '.$tool);
            }
        }
    }

    /** @return mixed the decoded JSON text of a successful tool call */
    private function tool(string $name, array $arguments = []): mixed
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        self::assertFalse($result['isError'] ?? false, $name.': '.($result['content'][0]['text'] ?? ''));

        return json_decode($result['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR);
    }

    private function toolError(string $name, array $arguments): string
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
        self::assertTrue($result['isError'] ?? false, $name.' should fail');

        return $result['content'][0]['text'];
    }

    /** @return array<string, mixed> the JSON-RPC result */
    private function rpc(string $method, array $params = []): array
    {
        if (null === $this->session) {
            $this->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
            $this->session = $this->client->getResponse()->headers->get('mcp-session-id');
            self::assertNotNull($this->session, 'the server opens a session');
            $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        }
        $this->post(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => (object) $params]);
        $body = (string) $this->client->getResponse()->getContent();
        // a streamable HTTP server may answer as server-sent events
        if (preg_match('/^data: (.+)$/m', $body, $m)) {
            $body = $m[1];
        }
        $response = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $response, json_encode($response['error'] ?? null));

        return $response['result'];
    }

    private function post(array $message): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        if (null !== $this->session) {
            $headers['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $headers, content: json_encode($message, \JSON_THROW_ON_ERROR));
    }
}
