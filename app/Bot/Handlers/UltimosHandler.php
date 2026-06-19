<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Services\Google\FirestoreService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando /ultimos [n] (M9.5 / T-006).
 *
 * Lista as últimas N transações do chat, ordenadas por data decrescente.
 * O parâmetro `n` (opcional) controla o tamanho da listagem:
 *
 *  - Sem parâmetro          → 5 transações (padrão).
 *  - `n` numérico 1..50     → exatamente `n` transações (CT-028).
 *  - `n` numérico > 50      → cap em 50 (Decisão Portão 2 #1; CT-028d).
 *  - `n` não numérico       → fallback silencioso em 5 (CT-028c).
 *  - `n` <= 0 ou negativo   → fallback silencioso em 5 (CT-028a, CT-028b).
 *
 * Quando o chat não tem transações registradas, exibe mensagem amigável
 * (CT-027a) incentivando o usuário a começar.
 *
 * O handler é **stateless puro** — não lê nem escreve sessão. Pode ser
 * invocado em qualquer estado da máquina conversacional sem afetar a
 * transação em andamento (CT-028f, Portão 2 #3).
 *
 * Ref.: docs/specs/m9-spec-fase-2.md §2.2, docs/planos/m9-plano-tecnico.md (T-006).
 */
final class UltimosHandler
{
    /**
     * Tamanho padrão da listagem quando o parâmetro é omitido.
     */
    public const int DEFAULT_LIMIT = 5;

    /**
     * Cap máximo (CT-028d, Decisão Portão 2 #1). Valores maiores que isto
     * são capados, não fallback (consistente com a tabela da Clarificação #6).
     */
    public const int MAX_LIMIT = 50;

    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $chatId = (string) (int) $message->chat->id;
        $text = (string) $message->getText();

        $n = $this->resolveLimit($text);

        try {
            $firestore = app(FirestoreService::class);
            $messenger = app(BotMessenger::class);
            $formatter = app(TransactionSummaryFormatter::class);

            $transactions = $firestore->listRecent($chatId, $n);
            $shown = count($transactions);

            if ($shown === 0) {
                $messenger->sendText(
                    $chatId,
                    "📭 <b>Nenhuma transação registrada</b> ainda.\n\n"
                    ."Envie uma mensagem descrevendo um gasto ou receita "
                    ."para começar! Exemplo:\n\n"
                    ."<i>Paguei R$ 47,50 no almoço hoje</i>",
                );

                return;
            }

            $messenger->sendText($chatId, $formatter->listSummary($transactions, $shown));
        } catch (\Throwable $e) {
            // Best-effort: loga e notifica o usuário. Nunca retornar 5xx ao Telegram.
            Log::error('UltimosHandler falhou', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            app(BotMessenger::class)->notifyError(
                $chatId,
                'Não consegui listar suas transações agora. Tente novamente em alguns instantes.',
            );
        }
    }

    /**
     * Decodifica o parâmetro `n` em /ultimos aplicando as regras da decisão.
     *
     * Heurística (Decisão Portão 2 #1 + plano §2 T-006):
     *  - Texto sem `/ultimos <param>` → DEFAULT_LIMIT (5).
     *  - `param` puramente numérico (ctype_digit) e > MAX_LIMIT → cap em MAX_LIMIT (50).
     *  - `param` puramente numérico mas < 1 (zero) → fallback DEFAULT_LIMIT (5).
     *  - `param` não numérico (inclui negativos) → fallback DEFAULT_LIMIT (5).
     *  - `param` numérico em [1, 50] → usa o valor exato.
     *
     * @return int Valor final a passar para `FirestoreService::listRecent()`.
     */
    private function resolveLimit(string $text): int
    {
        $matches = [];
        if (preg_match('/^\/ultimos(?:\s+(\S+))?/', $text, $matches) !== 1) {
            return self::DEFAULT_LIMIT;
        }

        $rawParam = $matches[1] ?? null;
        if ($rawParam === null) {
            return self::DEFAULT_LIMIT;
        }

        // Só números positivos são candidatos a valor real (ctype_digit exclui
        // sinal negativo e caracteres não-dígitos como 'abc').
        if (ctype_digit($rawParam)) {
            $value = (int) $rawParam;

            // Fora da faixa [1, 50] → ou cap (>50) ou fallback (zero/abaixo).
            if ($value > self::MAX_LIMIT) {
                return self::MAX_LIMIT;
            }
            if ($value < 1) {
                return self::DEFAULT_LIMIT;
            }

            return $value;
        }

        // Não numérico / negativo → fallback silencioso.
        return self::DEFAULT_LIMIT;
    }
}
