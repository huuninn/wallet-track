<?php

declare(strict_types=1);

namespace App\Actions;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;
use App\Services\Google\FirestoreService;
use App\Services\Google\SheetsService;
use Google\Service\Exception as GoogleServiceException;

/**
 * Orquestra o espelhamento de UMA transação Firestore → Google Sheets (M6.4/M6.5).
 *
 * Ponto único de entrada para o sincronismo síncrono (logo após persistir a
 * transação) ou assíncrono (cron de sync-pending, M9). Tenta o append na
 * planilha; em sucesso marca o documento Firestore como `synced`, em falha
 * marca como `failed` (com mensagem de erro + incremento de `sync_attempts`).
 *
 * **NÃO relança exceções esperadas de I/O**: o caller decide o que fazer. A
 * spec §10 diz que após 3 falhas o usuário é notificado — esse **limite de
 * retentativas e a notificação são responsabilidade do M9** (cron
 * sync-pending), que lê `sync_attempts` para decidir. Aqui apenas registramos
 * a tentativa (sucesso ou falha) no documento.
 *
 * **Escopo do catch (FIX-1)**: apenas `GoogleServiceException` (erros HTTP da
 * API Sheets: 403/404/429/500) e `\RuntimeException` (falhas de rede/timeout
 * propagadas pelo SDK/HTTP client) são tratadas como falha de sync. Demais
 * exceções — em especial `\InvalidArgumentException` lançada por
 * {@see SheetsService::appendTransaction()} quando o DTO está incompleto
 * (bug de programação) — **propagam**: marcar bug como `sync_status=failed`
 * poluiria o contador de retentativas e dispararia notificação ao usuário
 * de um problema que ele não causou.
 *
 * Implementa {@see SyncsSheet} (introduzida em M7.3) para desacoplar o
 * {@see ConversationRouter} desta implementação concreta.
 *
 * @see FirestoreService::updateSyncStatus()
 */
final class SyncSheet implements SyncsSheet
{
    public function __construct(
        private readonly SheetsService $sheets,
        private readonly FirestoreService $firestore,
    ) {}

    /**
     * Espelha a transação na planilha e atualiza o status de sync no Firestore.
     *
     * @param  string  $firestoreId  ID do documento em `transactions/`.
     * @return bool `true` em sucesso (sync_status=synced), `false` em falha
     *              esperada de I/O (sync_status=failed + sync_attempts
     *              incrementado). Exceções de programação (DTO incompleto,
     *              etc.) **propagam** ao caller — não devem ser mascaradas
     *              como falha de sync.
     */
    public function handle(TransactionData $dto, string $firestoreId): bool
    {
        try {
            $this->sheets->appendTransaction($dto, $firestoreId);
        } catch (GoogleServiceException|\RuntimeException $e) {
            // Apenas erros esperados de I/O da API Sheets (403/404/429/500) e
            // falhas de rede/timeout (RuntimeException propagada pelo SDK/HTTP
            // client). Bug de programação (ex.: InvalidArgumentException em
            // DTO incompleto) NÃO é capturado aqui — deve propagar para
            // evitar mascarar bugs como falhas de sync e poluir o contador
            // de retentativas lido pelo M9 (notificação ao usuário).
            $this->firestore->updateSyncStatus(
                $firestoreId,
                FirestoreService::SYNC_FAILED,
                $e->getMessage(),
            );

            return false;
        }

        $this->firestore->updateSyncStatus($firestoreId, FirestoreService::SYNC_SYNCED);

        return true;
    }
}
