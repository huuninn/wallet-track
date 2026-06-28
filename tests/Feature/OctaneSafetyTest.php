<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Conversation\ConversationRouter;
use App\Conversation\WizardHandler;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\UpdateType;
use Tests\TestCase;

/**
 * Suíte de segurança para o hardening do Laravel Octane (M5).
 *
 * Estes testes validam o isolamento de estado entre requests no mesmo
 * worker Octane (FrankenPHP) — a "rede de segurança" que garante que
 * as primitivas de reset {@see ConversationRouter::resetState()} e
 * {@see Nutgram::clear()} funcionam como esperado ANTES da ativação
 * do pipeline Octane (M6).
 *
 * **IMPORTANTE**: estes testes NÃO rodam sob Octane. Cada caso emula
 * o cenário de requests consecutivos chamando `resetState()` / `clear()`
 * manualmente dentro do mesmo teste, na mesma instância singleton,
 * validando que o estado mutável de um request não "vaza" para o seguinte.
 *
 * Cobertura:
 *
 *  - CT-OCT-01 → Nutgram `$store` é zerado após `clear()`
 *  - CT-OCT-02 → ConversationRouter `$cachedLabelCatalog` é resetado após `resetState()`
 *  - CT-OCT-03 → ConversationRouter `$wizardHandler` é resetado após `resetState()`
 *  - CT-OCT-04 → Handlers registrados no Nutgram são preservados após `clear()`
 */
class OctaneSafetyTest extends TestCase
{
    // -----------------------------------------------------------------
    // CT-OCT-01: Nutgram $store zerado após clear()
    // -----------------------------------------------------------------

    /**
     * Valida que o store interno do Nutgram (dados de update como chat_id,
     * message, callback_query) é completamente zerado após `clear()`,
     * garantindo que um request B não vê dados residuais do request A.
     */
    public function test_nutgram_store_is_cleared_after_clear(): void
    {
        $bot = new Nutgram('0000000000:test-token');

        // "Request A": handler seta um valor no store.
        $bot->set('chat_id', 111);
        $this->assertSame(111, $bot->get('chat_id'));

        // Simula listener RequestReceived (ResetNutgramState).
        $bot->clear();

        // "Request B" no MESMO singleton: store deve estar vazio.
        $this->assertNull($bot->get('chat_id'));
    }

    // -----------------------------------------------------------------
    // CT-OCT-02: ConversationRouter $cachedLabelCatalog resetado
    // -----------------------------------------------------------------

    /**
     * Valida que o cache em memória do catálogo de labels
     * ({@see ConversationRouter::$cachedLabelCatalog}) é invalidado após
     * {@see ConversationRouter::resetState()}, garantindo que um request
     * de um chat_id B não serve labels cacheadas do chat_id A.
     */
    public function test_router_cached_label_catalog_is_reset_after_reset_state(): void
    {
        $router = $this->createRouterWithoutConstructor();

        // Simula população do cache (como se um request anterior tivesse
        // chamado fetchLabelCatalog()).
        $ref = new \ReflectionClass($router);
        $prop = $ref->getProperty('cachedLabelCatalog');
        $prop->setValue($router, ['alimentação', 'transporte']);

        // Confirma que o cache foi populado.
        $this->assertNotNull($prop->getValue($router));
        $this->assertSame(['alimentação', 'transporte'], $prop->getValue($router));

        // Simula listener RequestReceived (ResetConversationRouter).
        $router->resetState();

        // Cache deve estar null (invalidado).
        $this->assertNull($prop->getValue($router));
    }

    // -----------------------------------------------------------------
    // CT-OCT-03: ConversationRouter $wizardHandler resetado
    // -----------------------------------------------------------------

    /**
     * Valida que o handler lazy do wizard `/nova`
     * ({@see ConversationRouter::$wizardHandler}) é descartado após
     * {@see ConversationRouter::resetState()}, garantindo que o wizard
     * de um chat_id A não interfira no request do chat_id B.
     *
     * Usa {@see \ReflectionClass::newInstanceWithoutConstructor()} para
     * criar uma instância de {@see WizardHandler} sem invocar seu
     * construtor (que exige 5 dependências), já que o reset apenas zera
     * a referência — não chama métodos do WizardHandler.
     */
    public function test_router_wizard_handler_is_reset_after_reset_state(): void
    {
        $router = $this->createRouterWithoutConstructor();

        // Cria WizardHandler sem constructor (evita o custo e a
        // complexidade de instanciar todas as dependências reais).
        $wizardRef = new \ReflectionClass(WizardHandler::class);
        $wizard = $wizardRef->newInstanceWithoutConstructor();

        // Injeta o WizardHandler simulado no Router.
        $routerRef = new \ReflectionClass($router);
        $prop = $routerRef->getProperty('wizardHandler');
        $prop->setValue($router, $wizard);

        // Confirma que foi injetado.
        $this->assertNotNull($prop->getValue($router));
        $this->assertInstanceOf(WizardHandler::class, $prop->getValue($router));

        // Simula listener RequestReceived (ResetConversationRouter).
        $router->resetState();

        // WizardHandler deve estar null (descartado).
        $this->assertNull($prop->getValue($router));
    }

    // -----------------------------------------------------------------
    // CT-OCT-04: Handlers do Nutgram preservados após clear()
    // -----------------------------------------------------------------

    /**
     * Valida que `Nutgram::clear()` NÃO remove os handlers registrados
     * (via `onMessage()`, `onCommand()`, etc.), apenas zera o `$store`
     * (dados do update atual).
     *
     * Esta é a premissa central da decisão de design P2-B: manter o
     * singleton Nutgram e aplicar `clear()` seletivo a cada request,
     * preservando o registro de handlers (~80 no bot atual) para evitar
     * o custo de re-registro.
     *
     * Estratégia: registramos um handler via `onMessage()`, verificamos
     * sua presença via reflexão em `$handlers`, chamamos `clear()`, e
     * confirmamos que o handler ainda está lá.
     */
    public function test_nutgram_handlers_preserved_after_clear(): void
    {
        $bot = new Nutgram('0000000000:test-token');

        // Registra um handler de mensagem (simula BotLoader::registerHandlers).
        $bot->onMessage(
            new class {
                public function __invoke(): void {}
            }
        );

        // Acessa a coleção de handlers via reflexão.
        // Em Nutgram 4, handlers são armazenados em $this->handlers
        // (protected, trait CollectHandlers), indexados por UpdateType.
        $messageKey = UpdateType::MESSAGE->value; // 'message'
        $handlersBefore = $this->getHandlersByType($bot, $messageKey);
        $this->assertNotEmpty(
            $handlersBefore,
            'Handler registrado via onMessage deve estar presente antes do clear()',
        );

        // Simula listener RequestReceived (ResetNutgramState).
        $bot->clear();

        // Handlers devem permanecer intactos.
        $handlersAfter = $this->getHandlersByType($bot, $messageKey);
        $this->assertNotEmpty(
            $handlersAfter,
            'Handler registrado deve permanecer após clear()',
        );
        $this->assertCount(
            count($handlersBefore),
            $handlersAfter,
            'clear() não deve alterar o número de handlers registrados',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Cria uma instância de {@see ConversationRouter} sem invocar o
     * construtor, adequada para testes que exercem apenas os métodos
     * de reset de estado (que não dependem de nenhuma dependência
     * injetada).
     *
     * Nota: a propriedade `$itemsParser` (private readonly) fica não
     * inicializada, mas `resetState()` não a acessa — somente `route()`
     * e `validateField()` dependem dela. O uso deste helper está
     * restrito aos testes CT-OCT-02 e CT-OCT-03.
     */
    private function createRouterWithoutConstructor(): ConversationRouter
    {
        $ref = new \ReflectionClass(ConversationRouter::class);

        $router = $ref->newInstanceWithoutConstructor();

        return $router;
    }

    /**
     * Acessa a propriedade `$handlers` do Nutgram via reflexão e
     * devolve os handlers registrados para um dado tipo de update.
     *
     * Em Nutgram 4, a propriedade `$handlers` é `protected array`
     * (trait {@see \SergiX44\Nutgram\Handlers\CollectHandlers}),
     * indexada por {@see UpdateType} → handlers.
     *
     * @return array<int, object>
     */
    private function getHandlersByType(Nutgram $bot, string $type): array
    {
        $ref = new \ReflectionObject($bot);

        // Nutgram usa CollectHandlers; a prop $handlers é protected.
        if (! $ref->hasProperty('handlers')) {
            $this->fail('Nutgram não tem propriedade "handlers" — versão incompatível?');
        }

        $prop = $ref->getProperty('handlers');
        $handlers = $prop->getValue($bot);

        if (! is_array($handlers)) {
            return [];
        }

        return $handlers[$type] ?? [];
    }
}
