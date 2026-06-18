<?php

declare(strict_types=1);

namespace Tests\Feature\DeepSeek;

use App\Services\DeepSeek\ChatCompleter;
use Throwable;

/**
 * Stub de teste que implementa {@see ChatCompleter} sem tocar na rede.
 *
 * Usado por {@see TextExtractionTest} para simular respostas do DeepSeek
 * (JSON de fixture pré-gravado) ou indisponibilidade (lançamento de exceção,
 * para CT-037). Garante que os testes do M3 sejam determinísticos e não
 * dependam de acesso à internet (CI).
 */
final class FakeChatCompleter implements ChatCompleter
{
    /** Prompt do sistema recebido na última chamada (para asserções de teste). */
    public ?string $systemPrompt = null;

    public function __construct(
        private readonly string $response = '',
        private readonly ?Throwable $throw = null,
    ) {}

    public function complete(string $systemPrompt, array $userMessages, array $options = []): string
    {
        $this->systemPrompt = $systemPrompt;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->response;
    }
}
