<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Mistral text to speech (Voxtral), called directly: the symfony/ai-mistral-platform release
 * KPA uses has no text-to-speech client yet. Stock voices only (Cast), no cloned voice.
 */
final readonly class Speech
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(env: 'MISTRAL_API_KEY')] #[\SensitiveParameter] private string $apiKey,
        #[Autowire(env: 'KPA_VOICE_MODEL')] private string $model,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Starts the request; read it with mp3(). Requests started together run concurrently. */
    public function start(string $text, string $voice): ResponseInterface
    {
        return $this->http->request('POST', 'https://api.mistral.ai/v1/audio/speech', [
            'auth_bearer' => $this->apiKey,
            'json' => ['model' => $this->model, 'input' => $text, 'voice_id' => $voice, 'response_format' => 'mp3'],
            'timeout' => 120,
        ]);
    }

    public function mp3(ResponseInterface $response): string
    {
        $data = $response->toArray();
        if (!is_string($data['audio_data'] ?? null)) {
            throw new \RuntimeException('The voice service returned no audio.');
        }

        return base64_decode($data['audio_data'], true) ?: throw new \RuntimeException('The voice service returned unreadable audio.');
    }
}
