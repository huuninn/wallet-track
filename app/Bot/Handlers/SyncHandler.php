<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Services\Google\FirestoreService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando `/sync` (M9.7 / T-012).
 *
 * Força a sincronização imediata das transações pendentes deste chat
 * com o Google Sheets, **resetando o contador de tentativas** (Decisão
 * Portão 2 #7) — o usuário que acabou de resolver um problema de acesso
 * ao Sheets quer "dar mais 3 chances" à transação, não esperar o próximo
 * cron ver `sync_attempts=3` e marcar como `failed` definitiva.
 *
 * **Lock atômico entre `/sync` e cron (Decisão Portão 2 #8)**: o
 * {@see FirestoreService::resetPendingSyncAttempts()} zera os contadores
 * SEM adquirir o flag `processing` — é uma operação segura para rodar
 * concorrentemente. Dentro do command `transactions:sync-pending`, o
 * lock é então respeitado via {@see FirestoreService::markSyncStarted()},
 * pulando transações que outra execução esteja processando.
 *
 * **Execução síncrona**: ao contrário do cron, o `/sync` é executado
 * em-processo na thread do webhook. A duração máxima típica é ~10s para
 * 20 transações (500ms/call Sheets API) — bem dentro do timeout de 300s
 * do Cloud Run. Vantagem: feedback IMEDIATO ao usuário com contadores
 * reais de synced/failed. Desvantagem: webhook fica bloqueado durante
 * a execução. Como `/sync` é raramente usado, o trade-off é favorável.
 *
 * **Notificação de falhas**: como o command é invocado com o `BotMessenger`
 * injetado, ele notificará o usuário UMA ÚNICA VEZ por transação (3ª falha
 * definitiva — Decisão Portão 2 #5 + #10).
 *
 * **Estado da sessão**: este handler é STATELESS (não toca em `sessions/`)
 * — pode ser invocado em qualquer estado da máquina conversacional sem
 * afetar a transação em andamento (CT-053).
 *
 * Ref.: docs/specs/m9-spec-fase-2.md §2.4, docs/planos/m9-plano-tecnico.md (T-012).
 */
final class SyncHandler
{
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $chatId = (int) $message->chat->id;
        $chatIdStr = (string) $chatId;

        $services = app();
        $firestore = $services->make(FirestoreService::class);
        $messenger = $services->make(BotMessenger::class);

        // 1. Reseta o contador de tentativas das pendentes deste chat.
        //    Decisão Portão 2 #7: /sync dá "mais 3 chances" ao usuário.
        $resetCount = $firestore->resetPendingSyncAttempts($chatIdStr);

        // 2. Se não há nada para sincronizar, responde amigável e sai.
        if ($resetCount === 0) {
            $messenger->sendText(
                $chatIdStr,
                "✅ <b>Nenhuma transação pendente</b> para sincronizar.\n\n"
                .'Todas as suas transações já estão na planilha! 🎉',
            );

            return;
        }

        // 3. Avisa que vai começar (feedback visual durante o sync).
        $messenger->sendText(
            $chatIdStr,
            "⏳ Sincronizando {$resetCount} transação(ões) pendente(s)...",
        );

        // 4. Executa o command in-process, passando o BotMessenger para
        //    que ele notifique o user em falhas definitivas.
        $exitCode = Artisan::call('transactions:sync-pending', [
            '--chat-id' => $chatIdStr,
            '--format' => 'json',
        ]);

        // 5. Parseia o output JSON do command.
        $output = trim(Artisan::output());
        $result = json_decode($output, true);
        if (! is_array($result)) {
            // Output inesperado — loga e responde genérico.
            Log::error('SyncHandler: output do command não é JSON parseável', [
                'chat_id' => $chatIdStr,
                'output' => $output,
                'exit_code' => $exitCode,
            ]);
            $messenger->sendText(
                $chatIdStr,
                '⚠️ <b>Sincronização concluída com erro</b> — não consegui ler o resultado. Verifique com /ultimos.',
            );

            return;
        }

        $synced = (int) ($result['synced'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $errors = $result['errors'] ?? [];

        // 6. Resposta final consolidada.
        $this->respondResult($messenger, $chatIdStr, $synced, $failed, $errors);
    }

    /**
     * Compõe a mensagem de resultado final do `/sync` (PT-BR).
     *
     * @param  list<array{id?: string, error?: string, attempts?: int}>  $errors
     */
    private function respondResult(
        BotMessenger $messenger,
        string $chatId,
        int $synced,
        int $failed,
        array $errors,
    ): void {
        if ($failed === 0) {
            $messenger->sendText(
                $chatId,
                "✅ <b>Sincronização concluída!</b>\n\n"
                ."   • {$synced} sincronizada(s) com sucesso 🎉",
            );

            return;
        }

        $lines = [
            ($synced > 0
                ? '✅ <b>Sincronização concluída com falhas parciais</b>'
                : '⚠️ <b>Sincronização falhou</b>'),
            '',
        ];
        if ($synced > 0) {
            $lines[] = "   • {$synced} sincronizada(s) com sucesso";
        }
        $lines[] = "   • {$failed} com falha";

        // Lista as falhas com mensagem curta (até 5 para não floodar).
        $shown = array_slice($errors, 0, 5);
        if ($shown !== []) {
            $lines[] = '';
            $lines[] = '<b>Falhas:</b>';
            foreach ($shown as $err) {
                $id = (string) ($err['id'] ?? '?');
                $msg = (string) ($err['error'] ?? 'erro desconhecido');
                $lines[] = "   • <code>{$id}</code>: {$msg}";
            }
            if (count($errors) > 5) {
                $lines[] = '   <i>… e mais '.(count($errors) - 5).' falha(s).</i>';
            }
        }

        $lines[] = '';
        $lines[] = '💡 Você já recebeu uma notificação por transação com 3 falhas. Tente /sync novamente após resolver o problema.';

        $messenger->sendText($chatId, implode("\n", $lines));
    }
}
