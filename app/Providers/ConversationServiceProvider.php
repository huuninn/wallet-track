<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\ExtractFromImage;
use App\Actions\ExtractFromText;
use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SyncSheet;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\NutgramBotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Conversation\ConversationRouter;
use App\Conversation\StateMachine;
use App\Services\Google\FirestoreService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use SergiX44\Nutgram\Nutgram;

/**
 * Registra a camada conversacional (M7) no container.
 *
 * Bindings:
 *
 *  - **Interfaces M7 → Implementações concretas** (ponto crucial):
 *    {@see ExtractsText} → {@see ExtractFromText}, {@see ExtractsImage} →
 *    {@see ExtractFromImage}, {@see SyncsSheet} → {@see SyncSheet}. Sem
 *    estes binds, a injeção no construtor de {@see ConversationRouter}
 *    falharia (Laravel auto-resolveria as interfaces para `new Interface()`
 *    que é erro de classe abstrata).
 *
 *  - **BotMessenger → NutgramBotMessenger**: a implementação concreta
 *    depende do singleton Nutgram, que por sua vez é registrado no
 *    {@see TelegramServiceProvider}. Usamos closure para resolver
 *    tardiamente (a ordem de registro dos providers no
 *    `bootstrap/providers.php` garante que Nutgram já está disponível).
 *
 *  - **TransactionSummaryFormatter**: stateless, registrado como singleton
 *    simples (auto-resolvido).
 *
 *  - **StateMachine**: puro, sem dependências. Auto-resolvido.
 *
 *  - **ConversationRouter**: singleton com 9 dependências injetadas
 *    automaticamente — a peça central do M7.
 *
 * Em testes, o ConversationRouter é instanciado diretamente com stubs
 * (ver {@see \Tests\Feature\Conversation\ConversationRouterTest}) — este
 * provider não é exercitado em unit tests.
 */
class ConversationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Interfaces M7 → implementações concretas. Os providers originais
        // (DeepSeekServiceProvider, GeminiServiceProvider, SheetsServiceProvider)
        // só registram as implementações; este provider é quem materializa
        // a abstração (M7.3).
        $this->app->bind(ExtractsText::class, ExtractFromText::class);
        $this->app->bind(ExtractsImage::class, ExtractFromImage::class);
        $this->app->bind(SyncsSheet::class, SyncSheet::class);

        // BotMessenger: depende do Nutgram (resolvido lazy via closure).
        $this->app->singleton(BotMessenger::class, function (Container $app): BotMessenger {
            return new NutgramBotMessenger(
                $app->make(Nutgram::class),
                $app->make(TransactionSummaryFormatter::class), // S-1: reusa singleton
            );
        });

        // TransactionSummaryFormatter: puro, singleton.
        $this->app->singleton(TransactionSummaryFormatter::class);

        // StateMachine: puro, auto-resolvido (sem necessidade de bind explícito).
        $this->app->singleton(StateMachine::class);

        // ConversationRouter: peça central do M7. Encapsula as 9 dependências
        // (5 services/ações + formatter + state machine + 2 ints da config).
        $this->app->singleton(ConversationRouter::class, function (Container $app): ConversationRouter {
            return new ConversationRouter(
                stateMachine: $app->make(StateMachine::class),
                messenger: $app->make(BotMessenger::class),
                formatter: $app->make(TransactionSummaryFormatter::class),
                firestore: $app->make(FirestoreService::class),
                extractText: $app->make(ExtractsText::class),
                extractImage: $app->make(ExtractsImage::class),
                syncSheet: $app->make(SyncsSheet::class),
                sessionTimeoutMinutes: (int) $app->make('config')->get('conversation.timeout_minutes', 15),
                maxDataRetries: (int) $app->make('config')->get('conversation.max_data_retries', 3),
            );
        });
    }
}
