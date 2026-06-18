<?php

declare(strict_types=1);

namespace App\Bot;

use App\Bot\Handlers\HelpHandler;
use App\Bot\Handlers\StartHandler;
use SergiX44\Nutgram\Nutgram;

/**
 * Centraliza o registro dos handlers de comandos do bot.
 *
 * É chamado pelo TelegramServiceProvider ao construir o singleton Nutgram
 * e também pode ser invocado em testes sobre um FakeNutgram, garantindo
 * que os mesmos handlers registrados em produção sejam exercitados.
 *
 * Comandos stateless (resposta única) usam classes invocáveis
 * (StartHandler/HelpHandler). Conversas multi-etapa (/nova, state machine)
 * serão adicionadas em M7/M9.
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
            ->description('Lista de comandos e exemplos');
    }
}
