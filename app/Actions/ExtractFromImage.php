<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\Gemini\GeminiService;
use App\Services\Telegram\TelegramFileDownloader;

/**
 * Action fina que orquestra a extração de imagem → TransactionData (M4.4).
 *
 * Resolve o {@see TelegramFileDownloader} (baixa a imagem do Telegram) e o
 * {@see GeminiService} (OCR multimodal → JSON → DTO), expondo um ponto único
 * de entrada para o fluxo conversacional (M5).
 *
 * O método {@see handle()} lança {@see ExtractionException} em falhas
 * estruturais (API indisponível, JSON malformado, imagem não-transação);
 * o chamador decide o fallback — tipicamente sugerir texto ou foto nítida
 * (spec §10, CT-008/CT-009), implementado em M5.
 *
 * Escopo DEFERIDO para M5:
 *  - M4.5: feedback de progresso em 4 etapas (editando mensagem Telegram) —
 *    precisa do router de mensagens/fluxo conversacional.
 *  - Tratamento do fallback `/nova` e oferta de canal alternativo.
 */
final class ExtractFromImage
{
    public function __construct(
        private readonly TelegramFileDownloader $downloader,
        private readonly GeminiService $service,
    ) {}

    /**
     * Baixa a imagem do Telegram e extrai a transação via Gemini.
     *
     * @param  string  $fileId  Identificador do arquivo no Telegram.
     * @return TransactionData Transação extraída e validada.
     *
     * @throws ExtractionException Em falha estrutural (API/JSON/não-transação).
     * @throws \RuntimeException Em falha de download do arquivo Telegram.
     */
    public function handle(string $fileId): TransactionData
    {
        $file = $this->downloader->download($fileId);

        return $this->service->extractFromImage($file->base64, $file->mimeType);
    }
}
