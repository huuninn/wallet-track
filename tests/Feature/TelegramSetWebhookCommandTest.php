<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramSetWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Garante config estável e previsível para os testes do command.
        config([
            'telegram.bot_token' => '123456789:FAKE-TOKEN-FOR-TESTS',
            'telegram.webhook_secret_token' => 'test-secret-token',
            'telegram.webhook_url' => 'https://example.test/webhook/telegram',
            'telegram.api_url' => 'https://api.telegram.org',
        ]);
    }

    /**
     * CT-M1-08: telegram:set-webhook chama a Bot API com a URL e o
     * secret token corretos e reporta sucesso.
     */
    public function test_set_webhook_calls_telegram_api_with_url_and_secret(): void
    {
        Http::fake([
            'api.telegram.org/bot123456789:FAKE-TOKEN-FOR-TESTS/setWebhook' => Http::response([
                'ok' => true,
                'description' => 'Webhook was set',
            ]),
        ]);

        $this->artisan('telegram:set-webhook')
            ->assertSuccessful()
            ->expectsOutput('✅ Webhook registrado com sucesso.');

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $payload['url'] === 'https://example.test/webhook/telegram'
                && $payload['secret_token'] === 'test-secret-token';
        });
    }

    /**
     * CT-M1-09: telegram:set-webhook falha (exit code FAILURE) com mensagem
     * amigável quando TELEGRAM_WEBHOOK_URL está vazio, sem chamar a API.
     */
    public function test_set_webhook_fails_when_url_is_empty(): void
    {
        config(['telegram.webhook_url' => null]);

        Http::fake();

        $this->artisan('telegram:set-webhook')
            ->assertFailed()
            ->expectsOutput('TELEGRAM_WEBHOOK_URL não está definido.');

        Http::assertNothingSent();
    }

    /**
     * CT-M1-08b (W3): telegram:set-webhook falha imediatamente quando
     * TELEGRAM_BOT_TOKEN não está configurado, ANTES de validar a URL e
     * sem chamar a API. Evita a URL ".../bot/setWebhook" (404/401 confuso).
     */
    public function test_set_webhook_fails_when_token_is_empty(): void
    {
        config(['telegram.bot_token' => null]);

        Http::fake();

        $this->artisan('telegram:set-webhook')
            ->assertFailed()
            ->expectsOutput('TELEGRAM_BOT_TOKEN não está configurado (verifique o .env).');

        Http::assertNothingSent();
    }

    /**
     * CT-M1-08c (W1): na falha da API, o comando NÃO exibe o body bruto
     * (que poderia vazar o secret_token em respostas de proxy/API alterada).
     * Mostra apenas os campos seguros (description) do JSON de erro.
     */
    public function test_set_webhook_shows_safe_error_without_leaking_body(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $this->artisan('telegram:set-webhook')
            ->assertFailed()
            ->expectsOutput('   Erro: Unauthorized')
            ->doesntExpectOutputToContain('error_code'); // não ecoa JSON bruto
    }

    /**
     * CT-M1-08d (W1): quando a resposta de erro não é JSON (ex.: HTML de
     * proxy), $response->json() retorna null e o comando mostra apenas o
     * status HTTP, sem vazar o corpo.
     */
    public function test_set_webhook_handles_non_json_error_response(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response('<html>Bad Gateway</html>', 502),
        ]);

        $this->artisan('telegram:set-webhook')
            ->assertFailed()
            ->expectsOutput('   Erro: HTTP 502');
    }

    /**
     * CT-M1-10: telegram:delete-webhook chama a Bot API e reporta sucesso.
     */
    public function test_delete_webhook_calls_telegram_api(): void
    {
        Http::fake([
            'api.telegram.org/bot123456789:FAKE-TOKEN-FOR-TESTS/deleteWebhook' => Http::response([
                'ok' => true,
                'description' => 'Webhook was deleted',
            ]),
        ]);

        $this->artisan('telegram:delete-webhook')
            ->assertSuccessful()
            ->expectsOutput('✅ Webhook removido com sucesso.');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST');
    }

    /**
     * CT-M1-11: telegram:delete-webhook --drop-pending-updates envia a flag
     * correspondente para a Bot API.
     */
    public function test_delete_webhook_can_drop_pending_updates(): void
    {
        Http::fake([
            'api.telegram.org/bot123456789:FAKE-TOKEN-FOR-TESTS/deleteWebhook' => Http::response([
                'ok' => true,
            ]),
        ]);

        $this->artisan('telegram:delete-webhook --drop-pending-updates')
            ->assertSuccessful();

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && ($request->data()['drop_pending_updates'] ?? null) === true;
        });
    }

    /**
     * CT-M1-10b (W3): telegram:delete-webhook falha imediatamente quando
     * TELEGRAM_BOT_TOKEN não está configurado, sem chamar a API.
     */
    public function test_delete_webhook_fails_when_token_is_empty(): void
    {
        config(['telegram.bot_token' => null]);

        Http::fake();

        $this->artisan('telegram:delete-webhook')
            ->assertFailed()
            ->expectsOutput('TELEGRAM_BOT_TOKEN não está configurado (verifique o .env).');

        Http::assertNothingSent();
    }
}
