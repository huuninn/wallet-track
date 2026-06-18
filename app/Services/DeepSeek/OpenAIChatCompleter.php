<?php

declare(strict_types=1);

namespace App\Services\DeepSeek;

use App\Exceptions\ExtractionException;
use Illuminate\Contracts\Config\Repository as Config;
use OpenAI;
use OpenAI\Client;
use Throwable;

/**
 * Implementação concreta de {@see ChatCompleter} sobre o `openai-php/client`.
 *
 * A API da DeepSeek é compatível com OpenAI: usamos a Factory do cliente
 * apontando a `base_url` para `https://api.deepseek.com`, e chamamos
 * `chat()->create([...])` forçando `response_format = json_object` para
 * garantir saída JSON estrita.
 *
 * Esta classe é registrada como singleton no DeepSeekServiceProvider. Em
 * testes, ela nunca é instanciada — bindamos um FakeChatCompleter no
 * container, mantendo DeepSeekService totalmente isolada da rede.
 */
final class OpenAIChatCompleter implements ChatCompleter
{
    private readonly Client $client;

    private readonly string $model;

    private readonly float $temperature;

    public function __construct(Config $config)
    {
        $apiKey = $config->string('deepseek.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('DeepSeek API key não configurada. Defina DEEPSEEK_API_KEY no arquivo .env.');
        }
        $baseUrl = $config->string('deepseek.base_url');
        $this->model = $config->string('deepseek.model');
        $this->temperature = $config->float('deepseek.temperature', 0.1);

        $this->client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->make();
    }

    public function complete(string $systemPrompt, array $userMessages, array $options = []): string
    {
        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $userMessages,
        );

        try {
            $response = $this->client->chat()->create([
                'model' => $options['model'] ?? $this->model,
                'temperature' => $options['temperature'] ?? $this->temperature,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ]);

            /** @var string|null $content */
            $content = $response->choices[0]->message->content ?? null;
        } catch (Throwable $e) {
            // Encapsula qualquer erro bruto (rede, autenticação, timeout) no
            // contrato único da camada de extração.
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Falha ao chamar a API do DeepSeek.',
                previous: $e,
            );
        }

        // Resposta 200 mas sem conteúdo textual — distingue de string vazia
        // (que seria tratada como INVALID_JSON pelo serviço). Sinal de falha
        // estrutural da API (ex.: filtro de conteúdo, resposta truncada).
        if ($content === null) {
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'API do DeepSeek retornou resposta sem conteúdo textual.',
            );
        }

        return $content;
    }
}
