<?php

declare(strict_types=1);

namespace App\Tests\Ai\Llm;

use App\Ai\Llm\LlmMessage;
use App\Ai\Llm\OpenAiCompatibleLlmClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiCompatibleLlmClientTest extends TestCase
{
    public function testBuildsOpenAiRequestAndParsesResponse(): void
    {
        $captured = new \stdClass();

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->method = $method;
            $captured->url = $url;
            $captured->body = $options['body'] ?? null;
            $captured->headers = $options['headers'] ?? [];

            return new MockResponse(
                json_encode([
                    'model' => 'qwen3.6:27b',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Réponse vulgarisée.']]],
                    'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 34],
                ], \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $client = new OpenAiCompatibleLlmClient($http, 'http://ia.homelab.lan:11434/v1', 'qwen3.6:27b', 'secret-token');

        $completion = $client->complete(
            [LlmMessage::system('Tu es un vulgarisateur scientifique.'), LlmMessage::user('Explique la photosynthèse.')],
            ['temperature' => 0.1, 'max_tokens' => 500],
        );

        // Réponse parsée.
        self::assertSame('Réponse vulgarisée.', $completion->content);
        self::assertSame('qwen3.6:27b', $completion->model);
        self::assertSame(12, $completion->promptTokens);
        self::assertSame(34, $completion->completionTokens);

        // Requête construite.
        self::assertSame('POST', $captured->method);
        self::assertSame('http://ia.homelab.lan:11434/v1/chat/completions', $captured->url);

        $payload = json_decode((string) $captured->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('qwen3.6:27b', $payload['model']);
        self::assertSame(0.1, $payload['temperature']);
        self::assertSame(500, $payload['max_tokens']);
        self::assertFalse($payload['stream']);
        self::assertCount(2, $payload['messages']);
        self::assertSame('system', $payload['messages'][0]['role']);
        self::assertSame('Explique la photosynthèse.', $payload['messages'][1]['content']);

        self::assertStringContainsString('Authorization: Bearer secret-token', implode("\n", $captured->headers));
    }
}
