<?php

declare(strict_types=1);

namespace App\Bot;

use App\Bot\Handlers\CallbackQueryRouterHandler;
use App\Bot\Handlers\CancelarHandler;
use App\Bot\Handlers\CategoriasHandler;
use App\Bot\Handlers\HelpHandler;
use App\Bot\Handlers\MessageRouterHandler;
use App\Bot\Handlers\StartHandler;
use App\Bot\Handlers\UltimosHandler;
use SergiX44\Nutgram\Nutgram;

/**
 * Centraliza o registro dos handlers de comandos do bot.
 *
 * É chamado pelo TelegramServiceProvider ao construir o singleton Nutgram
 * e também pode ser invocado em testes sobre um FakeNutgram, garantindo
 * que os mesmos handlers registrados em produção sejam exercitados.
 *
 * Ordem de registro importa: comandos (`/start`, `/help`, `/cancelar`,
 * `/ultimos`, `/categorias`) são registrados ANTES de `onMessage`/`onCallbackQuery`
 * (catch-all) — o Nutgram prioriza match exato de comando; só cai no
 * onMessage/onCallbackQuery se nenhum comando casou.
 */
final class BotLoader
{
    /**
     * Registra todos os handlers de comandos no bot.
     * Deve ser chamado antes da primeira execução de run()
     * (Nutgram lança StatusFinalizedException após o preflight).
     */
    public static function registerHandlers(Nutgram $bot): void
    {
        $bot->onCommand('start', StartHandler::class)
            ->description('Boas-vindas e instruções iniciais');

        $bot->onCommand('help', HelpHandler::class)
            ->description('Lista completa de comandos e exemplos');

        $bot->onCommand('cancelar', CancelarHandler::class)
            ->description('Cancela a operação atual e volta ao início');

        // M9.5 — /ultimos [n]: lista últimas N transações (T-006/T-008).
        $bot->onCommand('ultimos', UltimosHandler::class)
            ->description('Ver últimas N transações (padrão 5, máx 50)');

        // M9.6 — /categorias: lista com contador de uso (T-007/T-008).
        $bot->onCommand('categorias', CategoriasHandler::class)
            ->description('Listar categorias com contador de uso');

        // Catch-all: toda mensagem que NÃO é comando vira ConversationInput
        // e é roteada pelo ConversationRouter (M7.3, M7.4).
        $bot->onMessage(MessageRouterHandler::class);

        // Catch-all: todo toque em botão (Confirmar/Editar/Cancelar/...)
        // vira ConversationInput de callback e é roteado (M7.6, M7.7, M7.10).
        $bot->onCallbackQuery(CallbackQueryRouterHandler::class);
    }
}
