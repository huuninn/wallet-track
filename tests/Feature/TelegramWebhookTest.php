<?php

namespace Tests\Feature;

use App\Bot\BotLoader;
use App\Bot\Handlers\HelpHandler;
use App\Bot\Handlers\StartHandler;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    /**
     * Secret e chat_id usados como âncora em todos os testes deste arquivo.
     * Definidos em setUp() via config() para isolar do .env (que em dev
     * pode ter valores reais) — sem isso, o middleware do M2 rejeitaria
     * os POSTs por falta do header de secret token.
     */
    private const SECRET = 'm1-test-secret-token';

    private const ALLOWED_CHAT_ID = 5672987197;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixa a configuração esperada pelo middleware ValidateTelegramWebhook.
        // O chat_id do startPayload() abaixo já está nesta whitelist.
        config([
            'telegram.webhook_secret_token' => self::SECRET,
            'telegram.allowed_chat_ids' => [self::ALLOWED_CHAT_ID],
        ]);
    }

    /**
     * Headers HTTP que o Telegram anexa a cada webhook POST.
     * O secret é validado pelo middleware antes do controller (M2).
     */
    private function webhookHeaders(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];
    }

    /**
     * Payload realista de um update de /start enviado pelo Telegram.
     */
    private function startPayload(): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => self::ALLOWED_CHAT_ID, 'is_bot' => false, 'first_name' => 'Diego'],
                'chat' => ['id' => self::ALLOWED_CHAT_ID, 'type' => 'private'],
                'date' => 1718000000,
                'text' => '/start',
            ],
        ];
    }

    /**
     * Mocka o singleton Nutgram no container para que o controller não faça
     * chamadas de rede reais ao Telegram durante os testes de webhook.
     */
    private function mockNutgram(?callable $configure = null): Nutgram
    {
        $mock = Mockery::mock(Nutgram::class);

        // O controller sempre chama run(); por padrão, espera-se exatamente uma chamada.
        $expectation = $mock->shouldReceive('run')->once();

        if ($configure !== null) {
            $configure($expectation, $mock);
        }

        $this->app->bind(Nutgram::class, fn () => $mock);

        return $mock;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * CT-M1-01: webhook recebe payload válido de /start → 200 OK e o
     * Nutgram (run) é acionado exatamente uma vez.
     */
    public function test_webhook_accepts_start_update_and_returns_200(): void
    {
        $this->mockNutgram();

        $response = $this->postJson('/webhook/telegram', $this->startPayload(), $this->webhookHeaders());

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
    }

    /**
     * CT-M1-02: webhook recebe payload de /help → 200 OK.
     */
    public function test_webhook_accepts_help_update_and_returns_200(): void
    {
        $this->mockNutgram();

        $payload = $this->startPayload();
        $payload['update_id'] = 2;
        $payload['message']['text'] = '/help';

        $response = $this->postJson('/webhook/telegram', $payload, $this->webhookHeaders());

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
    }

    /**
     * CT-M1-03: webhook SEMPRE responde 200, mesmo quando o processamento
     * lança exceção (decisão arquitetural: evitar retries infinitos do
     * Telegram; erros vão para o log).
     *
     * Aqui forçamos run() a lançar e garantimos que o controller captura e
     * ainda retorna 200.
     */
    public function test_webhook_returns_200_even_when_processing_throws(): void
    {
        $this->mockNutgram(function ($expectation) {
            $expectation->andThrow(new \RuntimeException('boom'));
        });

        $response = $this->postJson('/webhook/telegram', $this->startPayload(), $this->webhookHeaders());

        $response->assertOk();
    }

    /**
     * CT-M1-03b (B1): webhook responde 200 mesmo diante de um \Error fatal
     * (bug de programação). O controller separa \Error (critical) de
     * \Exception (error) nos logs, mas ambos respeitam a regra do webhook.
     */
    public function test_webhook_returns_200_even_on_fatal_error(): void
    {
        $this->mockNutgram(function ($expectation) {
            $expectation->andThrow(new \Error('fatal boom'));
        });

        $response = $this->postJson('/webhook/telegram', $this->startPayload(), $this->webhookHeaders());

        $response->assertOk();
    }

    /**
     * CT-M1-04: webhook registra log estruturado para cada update recebido
     * (config/logging.php já envia para stderr JSON em produção).
     */
    public function test_webhook_logs_each_received_update(): void
    {
        $this->mockNutgram();

        Log::spy()
            ->shouldReceive('info')
            ->with('Telegram webhook: update recebido', Mockery::on(function ($ctx) {
                return is_array($ctx) && ($ctx['update_id'] ?? null) === 1;
            }));

        $this->postJson('/webhook/telegram', $this->startPayload(), $this->webhookHeaders())->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Handlers (FakeNutgram)
    |--------------------------------------------------------------------------
    | Exercitam os handlers reais registrados em produção (via BotLoader)
    | sobre um FakeNutgram, verificando conteúdo e comportamento das
    | mensagens de /start e /help — sem chamadas de rede.
    */

    /**
     * CT-M1-05: /start envia uma mensagem (sendMessage) e seu conteúdo é a
     * de boas-vindas em PT-BR, mencionando o nome do bot e os comandos.
     */
    public function test_start_handler_replies_with_ptbr_welcome(): void
    {
        $bot = Nutgram::fake();
        BotLoader::registerHandlers($bot);

        $bot->hearText('/start')->reply();

        $bot->assertCalled('sendMessage', 1);
        $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), 'Wallet Track'));
        $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), '/start'));
        $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), '/help'));
    }

    /**
     * CT-M1-06: /help lista TODOS os comandos planejados (mesmo os não
     * implementados), marcando quais estão ativos (✅) e quais ainda não (⏳).
     */
    public function test_help_handler_lists_all_planned_commands(): void
    {
        $bot = Nutgram::fake();
        BotLoader::registerHandlers($bot);

        $bot->hearText('/help')->reply();

        $bot->assertCalled('sendMessage', 1);

        foreach (HelpHandler::commands() as [$command]) {
            $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), $command));
        }

        // Marcadores de status: ativo (✅) e em breve (⏳).
        $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), '✅'));
        $bot->assertRaw(fn (Request $r) => str_contains((string) $r->getBody(), '⏳'));
    }

    /**
     * CT-M1-07 (unitário): a mensagem de /start e a lista de comandos de
     * /help são geradas a partir de fontes estáveis e testáveis.
     */
    public function test_handler_messages_are_ptbr_and_complete(): void
    {
        $start = StartHandler::message();
        $this->assertStringContainsString('Wallet Track', $start);
        $this->assertStringContainsString('controle financeiro', $start);

        $help = HelpHandler::message();
        $this->assertStringContainsString('/start', $help);
        $this->assertStringContainsString('/help', $help);
        $this->assertStringContainsString('/nova', $help);
        $this->assertStringContainsString('/cancelar', $help);
        $this->assertStringContainsString('/ultimos', $help);
        $this->assertStringContainsString('/categorias', $help);
        $this->assertStringContainsString('/sync', $help);
    }
}
