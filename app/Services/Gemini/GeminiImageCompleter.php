<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\ExtractionException;
use Gemini\Client;
use Gemini\Data\Blob;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Data\Schema;
use Gemini\Enums\MimeType;
use Gemini\Enums\ResponseMimeType;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;
use ValueError;

/**
 * Implementação concreta de {@see ImageCompleter} sobre o `google-gemini-php/client`.
 *
 * Monta o client Gemini (AI Studio, API Key simples), constrói os Parts
 * multimodais (texto do prompt + Blob da imagem base64), configura
 * GenerationConfig com responseMimeType=APPLICATION_JSON + responseSchema,
 * e chama generateContent.
 *
 * Esta classe é registrada como singleton no GeminiServiceProvider. Em
 * testes, ela nunca é instanciada — bindamos um FakeImageCompleter no
 * container, mantendo GeminiService totalmente isolada da rede.
 *
 * Confirmação da API (lida em vendor/google-gemini-php/client/src/):
 *  - Entry point: Gemini::client($key) → Client.
 *  - Modal: $client->generativeModel($model) → GenerativeModel.
 *  - withSystemInstruction(Content) define as regras comportamentais com
 *    peso de system instruction (README linhas 551-586 do pacote).
 *  - generateContent aceita variadic string|Blob|array|Content|... Cada
 *    argumento vira UM Content (via Content::parse). Por isso, ao passar
 *    prompt + imagem como args separados, o modelo vê 2 turns distintos
 *    (bug CRITICAL M4). Solução: prompt vira systemInstruction; a chamada
 *    recebe um ARRAY [Blob] → 1 Content com 1 Part (apenas a imagem do
 *    usuário), como manda o README (text-and-image, linhas 195-211).
 *  - Blob(mimeType: MimeType, data: string) — data é base64-encoded (docblock).
 *  - Resposta: $response->text() lança ValueError se não houver candidato/parte.
 */
final class GeminiImageCompleter implements ImageCompleter
{
    private readonly Client $client;

    private readonly string $model;

    private readonly float $temperature;

    public function __construct(Config $config)
    {
        $apiKey = $config->string('gemini.api_key');
        if ($apiKey === '') {
            throw new \RuntimeException('Gemini API key não configurada. Defina GEMINI_API_KEY no arquivo .env.');
        }

        $this->model = $config->string('gemini.model');
        $this->temperature = $config->float('gemini.temperature', 0.1);

        $this->client = \Gemini::client($apiKey);
    }

    public function complete(string $systemPrompt, string $base64Image, string $mimeType): string
    {
        // O enum MimeType do pacote cobre jpeg/png/webp/heic/heif. O mimeType
        // já foi validado pelo GeminiService, mas MimeType::from() lançaria
        // ValueError para valores não-listados — encapsulamos por segurança.
        $blob = new Blob(
            mimeType: MimeType::from($mimeType),
            data: $base64Image,
        );

        $schema = $this->loadResponseSchema();

        $generationConfig = new GenerationConfig(
            responseMimeType: ResponseMimeType::APPLICATION_JSON,
            responseSchema: $schema,
            temperature: $this->temperature,
        );

        // As regras comportamentais do prompt (ex.: "nunca invente dados",
        // "retorne APENAS JSON") têm peso de system instruction — guiam o
        // modelo com autoridade, sem competir com o conteúdo do usuário.
        // Content::parse(string) devolve um Content com uma Part(text).
        $systemInstruction = Content::parse($systemPrompt);

        try {
            $response = $this->client
                ->generativeModel($this->model)
                ->withSystemInstruction($systemInstruction)
                ->withGenerationConfig($generationConfig)
                // generateContent é variadic: cada arg vira UM Content
                // (turn separado). Passamos a imagem em ARRAY ÚNICO para
                // criar 1 Content com 1 Part (pattern README text-and-image,
                // linhas 195-211). O prompt vai via withSystemInstruction.
                ->generateContent([$blob]);

            $content = $response->text();
        } catch (ExtractionException $e) {
            throw $e;
        } catch (ValueError $e) {
            // text() lança ValueError quando a resposta não tem candidato
            // válido (ex.: prompt bloqueado por safety settings).
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Gemini não retornou conteúdo textual válido.',
                previous: $e,
            );
        } catch (Throwable $e) {
            // Encapsula qualquer erro bruto (rede, autenticação, timeout).
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Falha ao chamar a API do Gemini.',
                previous: $e,
            );
        }

        // text() devolve string (possivelmente vazia se o modelo não gerou
        // nada útil). O check de vazio fica AQUI, fora do try-catch, pois é
        // validação de domínio — não erro de transporte.
        if (trim($content) === '') {
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Gemini retornou resposta sem conteúdo textual.',
            );
        }

        return $content;
    }

    /**
     * Carrega o responseSchema de resources/gemini/transaction-schema.php.
     *
     * @phpstan-ignore-next-line (require de arquivo que retorna Schema)
     */
    private function loadResponseSchema(): Schema
    {
        /** @var Schema $schema */
        $schema = require resource_path('gemini/transaction-schema.php');

        return $schema;
    }
}
