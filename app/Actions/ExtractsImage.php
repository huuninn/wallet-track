<?php

declare(strict_types=1);

namespace App\Actions;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;

/**
 * Abstração da extração de imagem → TransactionData (M7.3).
 *
 * Desacopla o {@see ConversationRouter} da implementação
 * concreta ({@see ExtractFromImage}, que orquestra TelegramFileDownloader +
 * GeminiService), permitindo que os testes substituam por stubs simples.
 *
 * O contrato da implementação concreta é preservado:
 *  - Sucesso: devolve um {@see TransactionData} (possivelmente incompleto).
 *  - Falha estrutural (download/API/JSON/não-transação): lança
 *    {@see ExtractionException} — o Router captura e apresenta fallback.
 *
 * @see ExtractFromImage Implementação concreta usada em produção.
 */
interface ExtractsImage
{
    /**
     * Baixa a imagem do Telegram e extrai a transação.
     *
     * @param  string  $fileId  Identificador do arquivo no Telegram.
     * @return TransactionData Transação extraída (possivelmente incompleta).
     *
     * @throws ExtractionException Em falha estrutural (API/JSON/não-transação).
     */
    public function handle(string $fileId): TransactionData;
}
