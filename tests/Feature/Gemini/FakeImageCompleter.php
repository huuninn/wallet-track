<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Services\Gemini\ImageCompleter;
use Throwable;

/**
 * Stub de teste que implementa {@see ImageCompleter} sem tocar na rede.
 *
 * Usado por {@see ImageExtractionTest} e {@see ExtractFromImageTest} para
 * simular respostas do Gemini (JSON de fixture pré-gravado) ou
 * indisponibilidade (lançamento de exceção). Garante que os testes do M4
 * sejam determinísticos e não dependam de acesso à internet (CI).
 *
 * O prompt do sistema recebido é capturado em $systemPrompt para asserções
 * de teste (validação do cabeçalho temporal — espelho do M3).
 */
final class FakeImageCompleter implements ImageCompleter
{
    /** Prompt do sistema recebido na última chamada (para asserções de teste). */
    public ?string $systemPrompt = null;

    public function __construct(
        private readonly string $response = '',
        private readonly ?Throwable $throw = null,
    ) {}

    public function complete(string $systemPrompt, string $base64Image, string $mimeType): string
    {
        $this->systemPrompt = $systemPrompt;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->response;
    }
}
