<?php

declare(strict_types=1);

namespace App\Support\Telescope;

use App\Services\DeepSeek\ChatCompleter;
use Override;
use Throwable;

/**
 * Decorator que envolve {@see ChatCompleter} (DeepSeek via OpenAI-compat)
 * para registrar cada chamada de chat completion no Laravel Telescope.
 *
 * Método instrumentado: `complete()` (único da interface). O decorator:
 *  1. Mede latência com `hrtime(true)`.
 *  2. Chama o `wrapped` (delegação transparente).
 *  3. Em caso de SUCESSO: registra entry com `status: success` e o
 *     response da IA + `system_prompt`, `user_messages`, `options`.
 *  4. Em caso de ERRO: registra entry com `status: error`,
 *     `response: null` e a exceção, depois re-lança.
 *
 * **Diferente do Gemini:** este decorator armazena `user_messages`
 * INTEIRO no payload (sem truncar/hashear), porque o array de
 * mensagens é parte essencial do debug de chat completion. O tamanho
 * típico é pequeno (1-10 mensagens curtas), mas se a app evoluir para
 * multi-turn com histórico longo, considerar hash + truncamento
 * (decisão consciente da spec §5.3).
 *
 * **Por que `final readonly class`?** Este decorator não tem property
 * mutável — é seguro usar `readonly` (mesma justificativa do
 * {@see GeminiImageCompleterWatcherDecorator}).
 */
final readonly class DeepSeekChatCompleterWatcherDecorator implements ChatCompleter
{
    public function __construct(
        private ChatCompleter $wrapped,
    ) {}

    #[Override]
    public function complete(string $systemPrompt, array $userMessages, array $options = []): string
    {
        $start = hrtime(true);

        try {
            $response = $this->wrapped->complete($systemPrompt, $userMessages, $options);

            $this->recordEntry(
                systemPrompt: $systemPrompt,
                userMessages: $userMessages,
                options: $options,
                response: $response,
                latencyNs: hrtime(true) - $start,
                status: 'success',
            );

            return $response;
        } catch (Throwable $e) {
            $this->recordEntry(
                systemPrompt: $systemPrompt,
                userMessages: $userMessages,
                options: $options,
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
        array $userMessages,
        array $options,
        ?string $response,
        int $latencyNs,
        string $status,
        ?Throwable $exception = null,
    ): void {
        $content = [
            'name' => 'deepseek-chat-complete',
            'system_prompt' => $systemPrompt,
            'user_messages' => $userMessages,
            'options' => $options,
            'response' => $response,
            'latency_ms' => EntryPayload::nsToMs($latencyNs),
            'status' => $status,
            'exception' => EntryPayload::formatException($exception),
        ];

        EntryPayload::recordEvent($content, ['ai', 'deepseek', 'chat-completion', $status]);
    }
}
