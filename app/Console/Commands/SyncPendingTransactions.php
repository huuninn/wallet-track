<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Dto\TransactionData;
use App\Services\Google\FirestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa transações com `sync_status='pending'` (M9.8 / T-011).
 *
 * Disparado pelo Cloud Scheduler (cron a cada 5 min) e pelo handler
 * `/sync` (execução manual do usuário). Itera sobre as pendentes, espelha
 * cada uma no Google Sheets e atualiza o estado de sync no Firestore.
 *
 * Comando:
 * ```
 * php artisan transactions:sync-pending [--chat-id=CHAT_ID] [--limit=N] [--dry-run] [--format=text|json]
 * ```
 *
 * **Lock atômico `processing` (Decisão Portão 2 #8)**: cada transação é
 * adquirida via {@see FirestoreService::markSyncStarted()} antes de processar.
 * Se outra execução (cron paralelo, `/sync` simultâneo) já tem o lock, o
 * doc é pulado — evitando duplicação na planilha (Sheets API não é idempotente).
 *
 * **Política de retentativas (Decisão Portão 2 #5 + #7)**:
 *  - Tentativa 1 e 2 falhas → re-enfileira como `pending` para próxima rodada.
 *  - 3ª falha → marca `failed` definitivo, notifica o usuário UMA ÚNICA VEZ
 *    (carimbo `notified_at` impede re-notificação em execuções futuras).
 *  - `sync_attempts >= 3` → pula (CT-033e) — o doc já está em estado terminal.
 *
 * **Notificação (Decisão #10)**: via `BotMessenger::notifyError()` POR
 * TRANSAÇÃO, não em batch. O command recebe `BotMessenger` opcional:
 *  - `null` quando invocado pelo cron (sem chat pra notificar — apenas log).
 *  - injetado quando invocado pelo handler `/sync` (precisa notificar o user).
 *
 * Saída (text ou json):
 * ```
 * [INFO] Sincronizando 5 transações pendentes...
 * [INFO] Transação tx_001 sincronizada (row=42)
 * [ERROR] Transação tx_003 falhou: HTTP 500 (attempt 1/3)
 * [INFO] Concluído: 4 sincronizadas, 1 falhou.
 * ```
 *
 * Exit code:
 *  - `0` (SUCCESS) → execução OK, mesmo com falhas parciais (recuperáveis).
 *  - `1` (FAILURE) → erro de infraestrutura (Firestore/Sheets indisponíveis,
 *    timeout, etc.) — o orquestrador (cron) deve re-tentar.
 */
final class SyncPendingTransactions extends Command
{
    /**
     * Limite máximo de tentativas antes de marcar como `failed` definitivo
     * (CT-033d — Decisão Portão 2 #5). Hardcoded por enquanto; uma iteração
     * futura pode expor via config('sync.max_attempts').
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * Tamanho máximo de batch por execução (CT-033g). 20 transações cabem
     * em ~10s de latência Sheets API (500ms/call) — folga dentro do timeout
     * de 300s do Cloud Run.
     */
    public const int DEFAULT_LIMIT = 20;

    /**
     * {@inheritDoc}
     */
    protected $signature = 'transactions:sync-pending
        {--chat-id= : Processa apenas transações deste chat (uso do /sync manual)}
        {--limit= : Limite de transações por execução (default: 20)}
        {--dry-run : Lista as transações que SERIAM processadas, sem tocar Firestore/Sheets}
        {--format= : Formato de saída (text|json, default: text)}';

    /**
     * {@inheritDoc}
     */
    protected $description = 'Sincroniza transações pendentes (sync_status=pending) com Google Sheets';

    /**
     * Sem dependências injetadas no construtor (resolve via container em handle()).
     *
     * Por que não injetar no construtor? Porque o kernel do Artisan precisa
     * instanciar o command no boot para descobrir sua assinatura — e
     * `SyncsSheet` → `SyncSheet` → `SheetsService` exige credenciais Google
     * válidas. Resolver tardiamente em `handle()` evita que rodar QUALQUER
     * outro command (`firestore:seed-categories`, `telegram:set-webhook`)
     * force a inicialização do Sheets client.
     *
     * O `BotMessenger` é resolvido opcionalmente — null no cron (sem chat
     * pra notificar), instância quando invocado pelo handler `/sync`.
     */
    public function handle(): int
    {
        $format = (string) $this->option('format');
        $isJson = $format === 'json';
        $isDryRun = (bool) $this->option('dry-run');

        $chatIdOpt = $this->option('chat-id');
        $chatId = is_string($chatIdOpt) && $chatIdOpt !== '' ? $chatIdOpt : null;

        $limitOpt = $this->option('limit');
        $limit = is_string($limitOpt) && $limitOpt !== '' ? max(1, (int) $limitOpt) : self::DEFAULT_LIMIT;

        // FirestoreService é resolvido sempre (necessário mesmo no dry-run).
        /** @var FirestoreService $firestore */
        $firestore = app(FirestoreService::class);

        // Dry-run: só lista, sem side-effects. Não precisa de SyncsSheet
        // nem BotMessenger — esses só são úteis para processamento real.
        if ($isDryRun) {
            return $this->dryRun($firestore, $chatId, $limit, $isJson);
        }

        // SyncsSheet e BotMessenger: resolvidos tardiamente só quando vamos
        // realmente processar (evita falhar em dev/CI sem credenciais Sheets
        // quando o único uso é listar pendentes).
        /** @var SyncsSheet $syncSheet */
        $syncSheet = app(SyncsSheet::class);
        /** @var BotMessenger|null $messenger */
        $messenger = app()->bound(BotMessenger::class) ? app(BotMessenger::class) : null;

        $docs = $firestore->listPendingSync($chatId, $limit);

        if (! $isJson) {
            $this->info(sprintf(
                'Sincronizando %d transação(ões) pendente(s)...',
                count($docs),
            ));
        }

        $processed = 0;
        $synced = 0;
        $failed = 0;
        $errors = [];

        foreach ($docs as $doc) {
            $id = (string) $doc['id'];
            $data = (array) $doc['data'];
            $processed++;

            $result = $this->processOne($firestore, $syncSheet, $messenger, $id, $data, $isJson);

            if ($result['synced']) {
                $synced++;
            } else {
                $failed++;
                $errors[] = [
                    'id' => $id,
                    'attempts' => $result['attempts'],
                    'error' => $result['error'],
                ];
            }
        }

        if ($isJson) {
            $this->line((string) json_encode([
                'status' => 'ok',
                'processed' => $processed,
                'synced' => $synced,
                'failed' => $failed,
                'errors' => $errors,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Concluído: %d processadas, %d sincronizadas, %d falhou.',
                $processed,
                $synced,
                $failed,
            ));
        }

        // Exit code 0 mesmo com falhas parciais (recuperáveis) — o orquestrador
        // (cron) só re-tenta em exit≠0. Falha parcial é estado normal esperado
        // (Sheets offline temporariamente, etc).
        return self::SUCCESS;
    }

    /**
     * Dry-run: lista os IDs que SERIAM processados, sem tocar Firestore/Sheets.
     *
     * Útil para diagnóstico e smoke tests em produção (responde "o que o
     * próximo cron vai fazer?" sem efeitos colaterais).
     */
    private function dryRun(FirestoreService $firestore, ?string $chatId, int $limit, bool $isJson): int
    {
        // Para o dry-run, listamos COM o filtro de attempts<3 (igual à execução
        // real) — caso contrário, mentiria sobre o que seria processado.
        $docs = $firestore->listPendingSync($chatId, $limit);
        $ids = array_map(static fn (array $doc): string => (string) $doc['id'], $docs);

        if ($isJson) {
            $this->line((string) json_encode([
                'status' => 'ok',
                'mode' => 'dry-run',
                'would_process' => count($ids),
                'ids' => $ids,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'DRY-RUN: %d transação(ões) seria(m) processada(s).',
                count($ids),
            ));
            foreach ($ids as $id) {
                $this->line("  - {$id}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Processa UMA transação: sincroniza com Google Sheets.
     *
     * @param  array<string, mixed>  $data  Documento da transação (campos do schema `transactions/`).
     * @return array{synced: bool, attempts: int, error: ?string} Resultado do processamento:
     *                                                            - `synced`: bool — true se gravou no Sheets com sucesso
     *                                                            - `attempts`: int — contador final de tentativas (pós-incremento se falhou)
     *                                                            - `error`: string|null — descrição da falha (`null` em sucesso)
     *
     * **Comportamento de erros**:
     *
     *  - **Erros de negócio** (DTO inválido, falha de I/O do Sheets): são
     *    capturados DENTRO deste método, registrados em Firestore via
     *    `markSyncFailed`, e retornados como `synced=false` — o loop em
     *    `handle()` continua com a próxima transação.
     *  - **Erros de infraestrutura** (Firestore completamente indisponível,
     *    timeout, etc.) NÃO são capturados — propagam para `handle()` e
     *    MATAM o loop. O orquestrador (Cloud Scheduler) vê exit≠0 e
     *    re-tenta a execução completa na próxima rodada.
     *
     * Esta granularidade é intencional: o loop em `handle()` chama
     * `processOne()` SEM try/catch externo, porque a única falha que
     * ESCAPA é justamente a de infraestrutura — o caller decide se vale
     * a pena continuar (decidimos que NÃO vale: melhor abortar e re-tentar
     * do que iterar em cima de Firestore quebrado).
     */
    private function processOne(
        FirestoreService $firestore,
        SyncsSheet $syncSheet,
        ?BotMessenger $messenger,
        string $id,
        array $data,
        bool $isJson,
    ): array {
        $chatId = (string) ($data['chat_id'] ?? '');
        $source = (string) ($data['source'] ?? 'text');

        // 1. Tenta adquirir o lock atômico `processing`. Se outra execução
        // já tem o lock, pula (Decisão Portão 2 #8 — lock atômico).
        if (! $firestore->markSyncStarted($id)) {
            if (! $isJson) {
                $this->warn("Transação {$id} já em processamento — pulando.");
            }

            return ['synced' => false, 'attempts' => 0, 'error' => 'locked'];
        }

        try {
            $dto = TransactionData::fromArray($data);
        } catch (Throwable $e) {
            // DTO inválido (improvável — saveTransaction já validou) — marca
            // como failed mas SEM notificar (não é erro de sync do user).
            $firestore->markSyncFailed($id, 'invalid DTO: '.$e->getMessage());
            $doc = $firestore->getTransaction($id) ?? [];
            $attempts = (int) ($doc['sync_attempts'] ?? 0);

            if (! $isJson) {
                $this->error("Transação {$id} falhou: DTO inválido ({$e->getMessage()})");
            }

            return ['synced' => false, 'attempts' => $attempts, 'error' => 'invalid DTO'];
        }

        try {
            $success = $syncSheet->handle($dto, $id, $source);
        } catch (Throwable $e) {
            // Bug de programação escapou do SyncSheet (esperado que ele
            // capture erros de I/O, mas pode escapar DTO inválido, etc).
            // Marca como failed definitivo + notifica se >= 3 attempts.
            return $this->handleFailure($firestore, $messenger, $id, $dto, $chatId, $e->getMessage(), $isJson);
        }

        if ($success) {
            // SyncSheet já marcou sync_status=synced. Apenas carimbamos
            // o rowId (placeholder = Firestore id por ora) e liberamos lock.
            $firestore->markSyncSuccess($id, $id);

            if (! $isJson) {
                $this->info("Transação {$id} sincronizada (row={$id})");
            }

            return ['synced' => true, 'attempts' => 0, 'error' => null];
        }

        // Falha recuperável: SyncSheet já incrementou attempts e marcou
        // sync_status=failed. Re-lê o estado atual e decide:
        $fresh = $firestore->getTransaction($id) ?? [];
        $attempts = (int) ($fresh['sync_attempts'] ?? 0);
        $error = (string) ($fresh['sync_error_message'] ?? 'unknown');

        if ($attempts < self::MAX_ATTEMPTS) {
            // Re-enfileira como pending para próxima execução.
            // Usa requeuePendingSync (sem increment) em vez de updateSyncStatus
            // — o SyncSheet já incrementou; duplo-incremento quebraria o
            // limite de tentativas.
            $firestore->requeuePendingSync($id);

            if (! $isJson) {
                $this->warn(sprintf(
                    'Transação %s falhou: %s (attempt %d/%d) — re-enfileirada.',
                    $id,
                    $error,
                    $attempts,
                    self::MAX_ATTEMPTS,
                ));
            }

            return ['synced' => false, 'attempts' => $attempts, 'error' => $error];
        }

        return $this->handleFailure($firestore, $messenger, $id, $dto, $chatId, $error, $isJson, $attempts);
    }

    /**
     * Trata a transição definitiva para `failed` + notificação única.
     *
     * @return array{synced: bool, attempts: int, error: ?string}
     */
    private function handleFailure(
        FirestoreService $firestore,
        ?BotMessenger $messenger,
        string $id,
        TransactionData $dto,
        string $chatId,
        string $error,
        bool $isJson,
        ?int $attempts = null,
    ): array {
        // IMPORTANTE: checa notified_at ANTES de chamar markSyncFailed, pois
        // este último carimba o flag. Se checássemos depois, a 1ª falha
        // nunca notificaria (sempre veria notified_at setado por ele mesmo).
        $fresh = $firestore->getTransaction($id) ?? [];
        $alreadyNotified = ! empty($fresh['notified_at']);

        // Carimba o failed + notified_at (1ª vez) + libera lock.
        $firestore->markSyncFailed($id, $error);

        $finalAttempts = $attempts ?? (int) ($fresh['sync_attempts'] ?? 0);

        if (! $isJson) {
            $this->error(sprintf(
                'Transação %s falhou definitivamente após %d tentativas: %s',
                $id,
                $finalAttempts,
                $error,
            ));
        }

        // Notificação: SOMENTE se o BotMessenger foi injetado (caso do /sync)
        // E se ainda não foi notificado (idempotência via notified_at).
        // Decisão Portão 2 #5: notificação UMA ÚNICA VEZ por transação.
        if ($messenger !== null && $chatId !== '' && ! $alreadyNotified) {
            $messenger->notifyError($chatId, $this->formatFailedMessage($dto, $error));
        }

        return ['synced' => false, 'attempts' => $finalAttempts, 'error' => $error];
    }

    /**
     * Compõe a mensagem de notificação de falha definitiva (PT-BR, emoji
     * consistente com o resto do bot). Single source of truth — evita
     * divergência entre /sync e cron.
     */
    private function formatFailedMessage(TransactionData $dto, string $error): string
    {
        $description = (string) ($dto->description ?? '—');
        $amount = $dto->amount !== null ? 'R$ '.number_format((float) $dto->amount, 2, ',', '.') : '—';
        $type = $dto->type !== null ? ($dto->type === 'expense' ? 'Despesa' : 'Receita') : '—';
        $category = (string) ($dto->category ?? '—');
        $date = (string) ($dto->date ?? '—');

        return '⚠️ <b>Sincronização falhou</b> após '.self::MAX_ATTEMPTS." tentativas\n\n"
            ."<b>{$description}</b>\n"
            ."💰 {$amount} · {$type} · {$category}\n"
            ."📅 {$date}\n\n"
            ."Erro: {$error}\n\n"
            .'Use /sync para tentar novamente quando o problema for resolvido.';
    }
}
