<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestReceived;
use SergiX44\Nutgram\Nutgram;
use Throwable;

/**
 * Listener Octane que reseta o estado mutável por-request da instância
 * singleton do Nutgram.
 *
 * ## Por que este reset é necessário
 *
 * No modo Octane (FrankenPHP), o processo PHP é long-lived — múltiplas
 * requests HTTP são servidas pelo mesmo worker sem reinicialização do
 * container Laravel. A instância Nutgram é um singleton que acumula
 * estado entre requests através da trait {@see \SergiX44\Nutgram\Proxies\UpdateDataProxy},
 * cuja propriedade `$store` (array associativo interno) é populada
 * durante o processamento de cada update (dados do chat, user, message,
 * callback_query etc.) e **NÃO** é limpa automaticamente pelo
 * {@see \SergiX44\Nutgram\RunningMode\Webhook}.
 *
 * ### O problema do Webhook vs Polling
 *
 *  - **Polling** (`Polling::fire()`): chama `$bot->clear()` entre
 *    updates, zerando `$this->store = []`. Isso garante que cada update
 *    começa com estado limpo.
 *  - **Webhook** (`Webhook::processUpdates()`): NÃO chama `clear()`.
 *    O `UpdateDataProxy::$store` acumula dados do update anterior,
 *    causando **vazamento de estado entre requests** — um update do
 *    chat_id A pode deixar resíduos (ex.: `message_id`, `callback_data`,
 *    `chat`) que afetam o processamento do update seguinte (chat_id B).
 *
 * ### O que `Nutgram::clear()` faz
 *
 * O método {@see \SergiX44\Nutgram\Proxies\UpdateDataProxy::clear()}
 * é um método público estável da API do Nutgram 4 que simplesmente zera
 * o array interno:
 *
 * ```
 * $this->store = [];
 * ```
 *
 * Isso remove todos os proxies (`chat()`, `user()`, `message()`,
 * `callbackQuery()` etc.) sem afetar handlers registrados, configuração
 * de RunningMode, HTTP client, ou qualquer outro estado injetado.
 *
 * ### O que é preservado
 *
 *  - **Handlers registrados** (via `$bot->onText()`, `$bot->onCommand()`
 *    etc.) — o reset **não** desregistra handlers. O singleton é mantido
 *    para evitar o custo de re-registro a cada request (decisão P2-B).
 *  - **RunningMode** (Webhook config) — stateless, só guarda config.
 *  - **HTTP client, cache adapter, logger** — dependências injetadas.
 *
 * ### Decisão P2-B: singleton + reset seletivo
 *
 * Alternativas consideradas e rejeitadas:
 *  - **P2-A (rebind puro)**: recriar o Nutgram a cada request no
 *    container → alto custo de re-registro de handlers (~80 handlers
 *    no bot atual), perda de caching interno.
 *  - **P2-C (clear + rebind)**: combinação desnecessariamente complexa.
 *
 * A decisão P2-B mantém o singleton e aplica `clear()` seletivamente
 * no hook `RequestReceived`, preservando handlers e evitando overhead.
 *
 * ### Robustez
 *
 * O reset é envolto em try/catch defensivo. Uma falha aqui (ex.: o
 * método `clear()` mudar de assinatura em versão futura do Nutgram)
 * NÃO pode derrubar o request — estado stale é preferível a 500 em
 * TODAS as requests (este listener roda pré-controller).
 */
final class ResetNutgramState
{
    public function __construct(
        private readonly Nutgram $bot,
    ) {}

    public function handle(RequestReceived $event): void
    {
        try {
            $this->bot->clear();
        } catch (Throwable $e) {
            Log::warning('ResetNutgramState: falha ao limpar store do Nutgram', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
