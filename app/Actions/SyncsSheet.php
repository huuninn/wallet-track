<?php

declare(strict_types=1);

namespace App\Actions;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;

/**
 * Abstração do sincronismo WalletStore → Google Sheets (M7.3).
 *
 * Desacopla o {@see ConversationRouter} da implementação
 * concreta ({@see SyncSheet}, que orquestra SheetsService + WalletStore),
 * permitindo que os testes do Router substituam por stubs determinísticos.
 *
 * O contrato da implementação concreta é preservado:
 *  - Sucesso (sync_status=synced): devolve `true`.
 *  - Falha esperada de I/O (sync_status=failed, contabilizada): devolve `false`.
 *  - Bug de programação (DTO incompleto, etc.): **propaga** — não é mascarado
 *    como falha de sync.
 *
 * @see SyncSheet Implementação concreta usada em produção.
 */
interface SyncsSheet
{
    /**
     * Espelha a transação na planilha e atualiza o status de sync no WalletStore.
     *
     * @param  TransactionData  $dto  DTO completo (amount/type já validados).
     * @param  int  $txId  ID da transação no banco de dados.
     * @return bool `true` em sucesso, `false` em falha esperada de I/O.
     */
    public function handle(TransactionData $dto, int $txId): bool;
}
