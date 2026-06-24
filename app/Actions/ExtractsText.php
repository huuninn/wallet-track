<?php

declare(strict_types=1);

namespace App\Actions;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;

/**
 * Abstração da extração de texto livre → TransactionData (M7.3).
 *
 * Desacopla o {@see ConversationRouter} da implementação
 * concreta ({@see ExtractFromText}, que orquestra DeepSeekService), permitindo
 * que os testes do Router substituam por stubs simples (anonymous classes)
 * sem montar a cadeia completa de dependências HTTP.
 *
 * O contrato da implementação concreta é preservado:
 *  - Sucesso: devolve um {@see TransactionData} (possivelmente incompleto —
 *    campos pedíveis podem ser null; o Router decide o que pedir).
 *  - Falha estrutural (API/JSON/campos críticos): lança {@see ExtractionException}
 *    — o Router captura e apresenta mensagem amigável ao usuário.
 *
 * @see ExtractFromText Implementação concreta usada em produção.
 */
interface ExtractsText
{
    /**
     * Extrai uma transação do texto livre.
     *
     * @param  string  $text  Texto enviado pelo usuário (já normalizado).
     * @param  list<string>  $labelCatalog  Top-N labels do catálogo do usuário
     *                                      (display names), para injeção no prompt.
     * @return TransactionData Transação extraída (possivelmente incompleta).
     *
     * @throws ExtractionException Em falha estrutural (API/JSON/campos críticos).
     */
    public function handle(string $text, array $labelCatalog = []): TransactionData;
}
