<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Bot\BotLoader;
use App\Bot\Handlers\CancelarHandler;
use App\Bot\Handlers\CategoriasHandler;
use App\Bot\Handlers\HelpHandler;
use App\Bot\Handlers\StartHandler;
use App\Bot\Handlers\SyncHandler;
use App\Bot\Handlers\UltimosHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Testes do {@see BotLoader} (M9 — GAP-07 / T-018).
 *
 * Garante que os 6 comandos exatos estão registrados no Nutgram:
 *   /start, /help, /cancelar, /ultimos, /categorias, /sync.
 *
 * Estratégia: usar uma instância real do Nutgram e inspecionar as
 * `commands` registradas via reflexão. Evita mockar o Nutgram (a
 * interface `onCommand` é fluente — mockar com Mockery seria frágil).
 *
 * Roda isolado: `bin/dev test --filter "BotLoaderTest"`.
 */
#[CoversClass(BotLoader::class)]
class BotLoaderTest extends TestCase
{
    public function test_bot_loader_registers_all_m9_commands(): void
    {
        $bot = new Nutgram(config('telegram.bot_token', 'test-token'));
        BotLoader::registerHandlers($bot);

        $registered = $this->extractRegisteredCommands($bot);

        // Comandos esperados do M9 (6 do M9 + /nova da Fase D — não incluído aqui).
        $expected = [
            'start' => StartHandler::class,
            'help' => HelpHandler::class,
            'cancelar' => CancelarHandler::class,
            'ultimos' => UltimosHandler::class,
            'categorias' => CategoriasHandler::class,
            'sync' => SyncHandler::class,
        ];

        foreach ($expected as $command => $handlerClass) {
            $this->assertArrayHasKey($command, $registered, "Comando /{$command} deve estar registrado");
            $this->assertSame(
                $handlerClass,
                $registered[$command],
                "Comando /{$command} deve mapear para {$handlerClass}",
            );
        }
    }

    /**
     * Extrai o mapa de `comando => handler` registrado no Nutgram.
     *
     * O Nutgram guarda os handlers em `$handlers[updateType][messageType][pattern]`.
     * Para comandos de texto, a estrutura é `$handlers['message']['text'][$command]`.
     * Cada valor é um objeto `Command` (extends `Handler`) com `$callable` (protected).
     *
     * Acessamos via reflexão porque as propriedades são protected (mixin trait).
     *
     * @return array<string, string>
     */
    private function extractRegisteredCommands(Nutgram $bot): array
    {
        $reflection = new \ReflectionObject($bot);

        if (! $reflection->hasProperty('handlers')) {
            $this->fail('Nutgram não tem propriedade "handlers" — versão incompatível?');
        }

        $p = $reflection->getProperty('handlers');
        $p->setAccessible(true);
        $handlers = $p->getValue($bot);

        // Estrutura: $handlers['message']['text'][$command] = Command
        $textHandlers = $handlers['message']['text'] ?? [];

        $result = [];
        foreach ($textHandlers as $pattern => $command) {
            if (! is_object($command)) {
                continue;
            }

            $commandReflection = new \ReflectionObject($command);
            if (! $commandReflection->hasProperty('callable')) {
                continue;
            }

            $callableProp = $commandReflection->getProperty('callable');
            $callableProp->setAccessible(true);
            $callable = $callableProp->getValue($command);

            $result[(string) $pattern] = is_string($callable)
                ? $callable
                : (is_array($callable) ? implode('::', $callable) : 'unknown');
        }

        return $result;
    }
}
