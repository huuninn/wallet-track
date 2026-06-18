<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use SergiX44\Nutgram\Nutgram;
use Tests\TestCase;

/**
 * Cobertura de segurança do webhook do Telegram (M2).
 *
 * Mapeia os casos de teste manuais CT-034 (sem secret), CT-035 (secret
 * inválido) e CT-036 (chat_id não-autorizado), mais extras de robustez:
 * callback_query não-autorizado, update exótico sem remetente, e fail-closed
 * em misconfig (secret vazio / whitelist vazia).
 *
 * Roda isoladamente com: vendor/bin/phpunit --filter SecurityTest
 *
 * Nota sobre Log::spy(): usamos spy (permissivo) em vez de shouldReceive
 * (estrito) porque o framework eventualmente emite outros níveis de log
 * durante o ciclo de vida da requisição — strict mock faria o teste
 * quebrar em Mockery::BadMethodCallException no Log::error/info do handler
 * de exceções. spy registra tudo e nos deixa assercionar apenas o que
 * importa para o CT.
 */
class TelegramSecurityTest extends TestCase
{
    /**
     * Secret e chat_id do "dono" usados em todos os testes. O chat_id real
     * do dono (5672987197) é reutilizado para manter a semântica do bot
     * pessoal; outros valores simulam atacantes/usuários não-autorizados.
     */
    private const SECRET = 'm2-test-secret-token';

    private const ALLOWED_CHAT_ID = 5672987197;

    /**
     * Chat_id propositalmente fora da whitelist (sentinela) — nada além do
     * dono deve ser processado.
     */
    private const INTRUDER_CHAT_ID = 999999999;

    protected function setUp(): void
    {
        parent::setUp();

        // Ancora o estado esperado pelo middleware ValidateTelegramWebhook.
        // Usar config() (e não depender do phpunit.xml/.env) torna cada
        // cenário abaixo reproduzível independentemente do ambiente.
        config([
            'telegram.webhook_secret_token' => self::SECRET,
            'telegram.allowed_chat_ids' => [self::ALLOWED_CHAT_ID],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Headers HTTP que o Telegram anexa a cada webhook POST com secret token.
     */
    private function validSecretHeaders(): array
    {
        return ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET];
    }

    /**
     * Payload realista de mensagem (message.from.id = $fromId).
     */
    private function messagePayload(int $fromId, string $text = '/start'): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Tester'],
                'chat' => ['id' => $fromId, 'type' => 'private'],
                'date' => 1718000000,
                'text' => $text,
            ],
        ];
    }

    /**
     * Payload realista de callback_query (toque em botão de inline keyboard).
     */
    private function callbackQueryPayload(int $fromId): array
    {
        return [
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Tester'],
                'message' => [
                    'message_id' => 10,
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'WalletTrack'],
                    'chat' => ['id' => $fromId, 'type' => 'private'],
                    'date' => 1718000000,
                    'text' => 'Confirmar',
                ],
                'data' => 'confirm',
            ],
        ];
    }

    /**
     * Mocka o singleton Nutgram para que o controller não faça chamadas reais
     * ao Telegram. Usado apenas nos casos "happy path" (200 OK).
     */
    private function mockNutgramRunsOnce(): void
    {
        $mock = Mockery::mock(Nutgram::class);
        $mock->shouldReceive('run')->once();
        $this->app->bind(Nutgram::class, fn () => $mock);
    }

    /**
     * Asseção auxiliar: valida que o contexto de log bloqueado contém
     * reason, chat_id (quando esperado) e ip. Usada em todos os CTs de
     * bloqueio para reduzir boilerplate e padronizar o contrato do log.
     */
    public static function blockedContextMatches(
        ?string $expectedReason,
        ?int $expectedChatId,
        mixed $ctx,
        bool $chatIdMustBeExact,
    ): bool {
        if (! is_array($ctx)) {
            return false;
        }

        if ($expectedReason !== null && ($ctx['reason'] ?? null) !== $expectedReason) {
            return false;
        }

        if ($chatIdMustBeExact && ($ctx['chat_id'] ?? null) !== $expectedChatId) {
            return false;
        }

        // ip deve estar sempre presente (mesmo que seja '127.0.0.1' em testes).
        return array_key_exists('ip', $ctx);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-034 — Secret token faltante
    |--------------------------------------------------------------------------
    */

    /**
     * CT-034: POST válido mas SEM o header X-Telegram-Bot-Api-Secret-Token.
     * Esperado: 401 Unauthorized + Log::warning reason=missing_secret_token.
     */
    public function test_security_rejects_request_without_secret_token_header(): void
    {
        Log::spy();

        $response = $this->postJson('/webhook/telegram', $this->messagePayload(self::ALLOWED_CHAT_ID));

        $response->assertUnauthorized();
        $response->assertJson(['error' => 'unauthorized']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('missing_secret_token', null, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | CT-035 — Secret token inválido
    |--------------------------------------------------------------------------
    */

    /**
     * CT-035: POST com header de secret token divergente do esperado.
     * Esperado: 401 Unauthorized + Log::warning reason=invalid_secret_token.
     */
    public function test_security_rejects_request_with_invalid_secret_token(): void
    {
        Log::spy();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::ALLOWED_CHAT_ID),
            ['X-Telegram-Bot-Api-Secret-Token' => 'this-is-not-the-correct-secret']
        );

        $response->assertUnauthorized();
        $response->assertJson(['error' => 'unauthorized']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('invalid_secret_token', null, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | CT-036 — chat_id fora da whitelist
    |--------------------------------------------------------------------------
    */

    /**
     * CT-036: POST com secret correto + message.from.id não-autorizado.
     * Esperado: 403 Forbidden + Log::warning reason=chat_id_not_allowed,
     * incluindo o chat_id do atacante (útil para detecção de abuso).
     */
    public function test_security_rejects_message_from_chat_id_not_in_whitelist(): void
    {
        Log::spy();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::INTRUDER_CHAT_ID),
            $this->validSecretHeaders()
        );

        $response->assertForbidden();
        $response->assertJson(['error' => 'forbidden']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('chat_id_not_allowed', self::INTRUDER_CHAT_ID, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | Sanity — chat_id autorizado passa e processa
    |--------------------------------------------------------------------------
    */

    /**
     * Sanity: POST com secret correto + chat_id do dono na whitelist.
     * Esperado: 200 OK e o Nutgram::run() é chamado exatamente uma vez
     * (ou seja, o controller assumiu o processamento normalmente).
     */
    public function test_security_allows_authorized_chat_id_and_runs_bot(): void
    {
        $this->mockNutgramRunsOnce();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::ALLOWED_CHAT_ID),
            $this->validSecretHeaders()
        );

        $response->assertOk();
        $this->assertSame('OK', $response->getContent());
    }

    /*
    |--------------------------------------------------------------------------
    | Extra — callback_query não-autorizado
    |--------------------------------------------------------------------------
    */

    /**
     * Extra: callback_query (toque em botão) com from.id não-autorizado.
     * Garante que a extração do chat_id funciona também para callbacks.
     */
    public function test_security_rejects_unauthorized_callback_query(): void
    {
        Log::spy();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->callbackQueryPayload(888888888),
            $this->validSecretHeaders()
        );

        $response->assertForbidden();
        $response->assertJson(['error' => 'forbidden']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('chat_id_not_allowed', 888888888, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /**
     * Extra sanity: callback_query autorizado passa (toque válido em botão).
     */
    public function test_security_allows_authorized_callback_query(): void
    {
        $this->mockNutgramRunsOnce();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->callbackQueryPayload(self::ALLOWED_CHAT_ID),
            $this->validSecretHeaders()
        );

        $response->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Extra — update exótico (sem remetente identificável)
    |--------------------------------------------------------------------------
    */

    /**
     * Extra: update de tipo exótico (poll) sem campo `from`.
     * Esperado: 403 Forbidden + reason=unidentifiable_sender (fail-closed).
     *
     * Justificativa: o bot é pessoal e só processa updates atribuíveis a um
     * usuário da whitelist. Updates sem remetente (poll, chat_member, etc.)
     * são rejeitados silenciosamente para evitar processar eventos que não
     * conseguimos auditar.
     */
    public function test_security_rejects_update_without_identifiable_sender(): void
    {
        Log::spy();

        $payload = [
            'update_id' => 3,
            'poll' => [
                'id' => 'poll-1',
                'question' => 'x',
                'options' => [
                    ['text' => 'a', 'voter_count' => 0],
                    ['text' => 'b', 'voter_count' => 0],
                ],
                'total_voter_count' => 0,
                'is_closed' => false,
                'is_anonymous' => true,
                'type' => 'regular',
                'allows_multiple_answers' => false,
            ],
        ];

        $response = $this->postJson('/webhook/telegram', $payload, $this->validSecretHeaders());

        $response->assertForbidden();
        $response->assertJson(['error' => 'forbidden']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('unidentifiable_sender', null, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | Extra — fail-closed em misconfig
    |--------------------------------------------------------------------------
    */

    /**
     * Misconfig (fail-closed): TELEGRAM_WEBHOOK_SECRET_TOKEN vazio.
     * Esperado: 401 Unauthorized + Log::critical (sem abrir exceção de
     * segurança por conveniência — o dono precisa configurar o secret).
     *
     * Não esperamos Log::warning aqui porque a misconfig é estrutural
     * (não é uma tentativa isolada de ataque).
     */
    public function test_security_fails_closed_when_secret_token_is_empty(): void
    {
        config(['telegram.webhook_secret_token' => null]);

        Log::spy();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::ALLOWED_CHAT_ID),
            $this->validSecretHeaders()
        );

        $response->assertUnauthorized();
        $response->assertJson(['error' => 'unauthorized']);

        Log::shouldHaveReceived('critical')
            ->with(Mockery::on(fn ($m): bool => is_string($m)
                && str_contains($m, 'TELEGRAM_WEBHOOK_SECRET_TOKEN')
                && str_contains($m, 'fail-closed')))
            ->once();

        // Misconfig estrutural não deve gerar warning de "tentativa bloqueada".
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Misconfig (fail-closed): whitelist vazia → nenhum chat_id autorizado.
     * Esperado: 403 Forbidden para qualquer update com remetente válido.
     *
     * O TelegramServiceProvider::boot() já avisa sobre whitelist vazia na
     * inicialização; este teste confirma que o middleware executa a política.
     */
    public function test_security_fails_closed_when_whitelist_is_empty(): void
    {
        config(['telegram.allowed_chat_ids' => []]);

        Log::spy();

        $response = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::ALLOWED_CHAT_ID),
            $this->validSecretHeaders()
        );

        $response->assertForbidden();
        $response->assertJson(['error' => 'forbidden']);

        Log::shouldHaveReceived('warning')
            ->with('Telegram webhook: requisição bloqueada', Mockery::on(
                fn ($ctx): bool => self::blockedContextMatches('chat_id_not_allowed', self::ALLOWED_CHAT_ID, $ctx, chatIdMustBeExact: true)
            ))
            ->once();
    }

    /*
    |--------------------------------------------------------------------------
    | Extra — respostas HTTP não vazam detalhes
    |--------------------------------------------------------------------------
    */

    /**
     * Garante que as respostas 401/403 carregam apenas {'error': '...'}
     * genérico — sem payload, sem IP, sem reason. Defender contra info
     * disclosure que ajudaria atacantes a fingerprintear a validação.
     */
    public function test_security_responses_never_leak_internal_details(): void
    {
        Log::spy();

        // 401 sem header → apenas error=unauthorized.
        $r1 = $this->postJson('/webhook/telegram', $this->messagePayload(self::ALLOWED_CHAT_ID));
        $r1->assertUnauthorized();
        $this->assertSame(['error' => 'unauthorized'], $r1->json());

        // 403 chat_id inválido → apenas error=forbidden.
        $r2 = $this->postJson(
            '/webhook/telegram',
            $this->messagePayload(self::INTRUDER_CHAT_ID),
            $this->validSecretHeaders()
        );
        $r2->assertForbidden();
        $this->assertSame(['error' => 'forbidden'], $r2->json());
    }
}
