<?php

declare(strict_types=1);

namespace App\Support\Telescope;

use App\Services\Gemini\ImageCompleter;
use Override;
use Throwable;

/**
 * Decorator que envolve {@see ImageCompleter} (Gemini multimodal) para
 * registrar cada chamada de OCR no Laravel Telescope.
 *
 * Método instrumentado: `complete()` (único da interface). O decorator:
 *  1. Calcula `image_size_bytes` (strlen do base64) e `image_hash_sha1`
 *     ANTES do try — assim mesmo se a chamada falhar, temos os
 *     metadados para correlacionar.
 *  2. Mede latência com `hrtime(true)`.
 *  3. Chama o `wrapped` (delegação transparente).
 *  4. Em caso de SUCESSO: registra entry com `status: success`,
 *     response da IA e metadados calculados.
 *  5. Em caso de ERRO: registra entry com `status: error`,
 *     `response: null` e a exceção, depois re-lança.
 *
 * **NÃO armazena a string base64 da imagem** — apenas metadados
 * (tamanho + hash). Strings base64 de 10MB gerariam entries enormes
 * no SQLite e potencialmente estourarem limites de storage.
 *
 * **Por que `final readonly class`?** Este decorator não tem property
 * mutável — é seguro usar `readonly` (diferente do
 * o antigo FirestoreWatcherDecorator (deletado em M7), que tinha `$nestedOperationCount`).
 */
final readonly class GeminiImageCompleterWatcherDecorator implements ImageCompleter
{
    public function __construct(
        private ImageCompleter $wrapped,
    ) {}

    #[Override]
    public function complete(string $systemPrompt, string $base64Image, string $mimeType): string
    {
        $start = hrtime(true);

        // Calcula metadados UMA vez antes do try — disponíveis tanto em
        // sucesso quanto em erro (para correlacionar a imagem com o hash).
        $imageSizeBytes = strlen($base64Image);
        $imageHash = sha1($base64Image);

        try {
            $response = $this->wrapped->complete($systemPrompt, $base64Image, $mimeType);

            $this->recordEntry(
                systemPrompt: $systemPrompt,
                mimeType: $mimeType,
                imageSizeBytes: $imageSizeBytes,
                imageHash: $imageHash,
                response: $response,
                latencyNs: hrtime(true) - $start,
                status: 'success',
            );

            return $response;
        } catch (Throwable $e) {
            $this->recordEntry(
                systemPrompt: $systemPrompt,
                mimeType: $mimeType,
                imageSizeBytes: $imageSizeBytes,
                imageHash: $imageHash,
                response: null,
                latencyNs: hrtime(true) - $start,
                status: 'error',
                exception: $e,
            );
            throw $e;
        }
    }

    private function recordEntry(
        string $systemPrompt,
        string $mimeType,
        int $imageSizeBytes,
        string $imageHash,
        ?string $response,
        int $latencyNs,
        string $status,
        ?Throwable $exception = null,
    ): void {
        $content = [
            'name' => 'gemini-image-complete',
            'system_prompt' => $systemPrompt,
            'mime_type' => $mimeType,
            'image_size_bytes' => $imageSizeBytes,
            'image_hash_sha1' => $imageHash,
            'response' => $response,
            'latency_ms' => EntryPayload::nsToMs($latencyNs),
            'status' => $status,
            'exception' => EntryPayload::formatException($exception),
        ];

        EntryPayload::recordEvent($content, ['ai', 'gemini', 'image-ocr', $status]);
    }
}
