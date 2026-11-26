<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ToolCallLog;
use League\CommonMark\CommonMarkConverter;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A chat over the catalog: the agent (config/packages/ai.yaml, kpa_chat) searches and reads
 * songs with the same tools as the MCP server, and answers from what they return. The session
 * holds the conversation id; the turns are kept in the cache for a week.
 */
final class AskController extends AbstractController
{
    private const CONVERSATION = 'kpa_chat_id';
    private const MAX_QUESTION = 1000;

    public function __construct(
        #[Autowire(service: 'ai.agent.kpa_chat')] private readonly AgentInterface $agent,
        private readonly ToolCallLog $log,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('/ask', name: 'ask', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('ask/index.html.twig', ['turns' => $this->turns($this->conversation($request))]);
    }


    #[Route('/ask', name: 'ask_post', methods: ['POST'])]
    public function ask(Request $request): RedirectResponse
    {
        $question = trim(mb_substr((string) $request->request->get('question', ''), 0, self::MAX_QUESTION));
        if ('' !== $question) {
            $id = $this->conversation($request);
            $this->answer($id, $question);
        }

        return $this->redirectToRoute('ask', ['_fragment' => 'latest']);
    }

    #[Route('/ask/reset', name: 'ask_reset', methods: ['POST'])]
    public function reset(Request $request): RedirectResponse
    {
        $request->getSession()->remove(self::CONVERSATION);

        return $this->redirectToRoute('ask');
    }

    /** @return array<string, mixed> the new turn */
    private function answer(Uuid $id, string $question): array
    {
        $turns = $this->turns($id);
        $messages = [];
        foreach (array_slice($turns, -6) as $turn) {
            $messages[] = Message::ofUser($turn['question']);
            $messages[] = Message::ofAssistant($turn['answer']);
        }
        $messages[] = Message::ofUser($question);

        $this->log->reset();
        try {
            $answer = trim((string) $this->agent->call(new MessageBag(...$messages))->getContent());
            $turn = ['question' => $question, 'answer' => $answer, 'tools' => $this->log->calls()];
        } catch (\Throwable $e) {
            $turn = ['question' => $question, 'answer' => 'The assistant could not answer: '.$e->getMessage(), 'tools' => $this->log->calls(), 'failed' => true];
        }
        $turn['html'] = (string) (new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($turn['answer']);

        $turns[] = $turn;
        $this->cache->delete('kpa_chat_turns_'.$id);
        $this->cache->get('kpa_chat_turns_'.$id, static function (ItemInterface $item) use ($turns): array {
            $item->expiresAfter(7 * 86400);

            return $turns;
        });

        return $turn;
    }

    private function conversation(Request $request): Uuid
    {
        $session = $request->getSession();
        if (!$session->has(self::CONVERSATION)) {
            $session->set(self::CONVERSATION, Uuid::v7()->toRfc4122());
        }

        return Uuid::fromString($session->get(self::CONVERSATION));
    }

    /** @return list<array<string, mixed>> */
    private function turns(Uuid $id): array
    {
        return $this->cache->get('kpa_chat_turns_'.$id, static fn (): array => []);
    }
}
