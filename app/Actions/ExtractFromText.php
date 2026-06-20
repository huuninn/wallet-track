<?php

declare(strict_types=1);

namespace App\Actions;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\DeepSeek\DeepSeekService;

/**
 * Action fina que orquestra a extração de texto → TransactionData (M3.4).
 *
 * Resolve o {@see DeepSeekService} do container e expõe um ponto único de
 * entrada para o fluxo conversacional (M7). O método {@see handle()} lança
 * {@see ExtractionException} em falhas estruturais (API indisponível, JSON
 * malformado, campos obrigatórios ausentes); o chamador decide o fallback
 * — tipicamente sugerir o wizard `/nova` (spec §10, CT-037).
 *
 * Nota de escopo: campos "pedíveis" ausentes (valor/tipo) NÃO chegam aqui
 * como exceção — retornam null no DTO, e o {@see ConversationRouter}
 * pergunta ao usuário.
 *
 * Implementa {@see ExtractsText} (introduzida em M7.3) para desacoplar o
 * Router desta implementação concreta — permitindo testes com stubs.
 */
final class ExtractFromText implements ExtractsText
{
    public function __construct(
        private readonly DeepSeekService $service,
    ) {}

    /**
     * Extrai uma transação do texto livre.
     *
     * @throws ExtractionException Em falha estrutural (API/JSON/campos críticos).
     */
    public function handle(string $text): TransactionData
    {
        return $this->service->extract($text);
    }
}
