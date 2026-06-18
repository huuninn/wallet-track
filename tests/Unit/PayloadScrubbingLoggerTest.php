<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bot\Logging\PayloadScrubbingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

/**
 * Testes unitários do decorator PayloadScrubbingLogger.
 *
 * São testes puros (não dependem do container do Laravel): o logger interno
 * é um spy anônimo que grava cada chamada para inspeção.
 *
 * Cobre os critérios de aceite do W1 (higiene de logs do webhook do Nutgram):
 *  - "Update processed" e "Update failed" têm o payload redigido.
 *  - Mensagens comuns do Nutgram e do Laravel passam inalteradas.
 *  - Mensagens com JSON no meio, mas sem casar com o padrão, passam inalteradas.
 *  - TODOS os 9 métodos da interface aplicam o scrub (dataProvider).
 *  - O $context é sempre repassado inalterado (preserva a exceção do "Update failed").
 */
class PayloadScrubbingLoggerTest extends TestCase
{
    /**
     * Spy que implementa LoggerInterface e grava cada chamada em $calls[]
     * como ['level' => string, 'message' => string, 'context' => array].
     *
     * O $level de log() é o recebido (string); para os demais métodos é o
     * nome do método (PSR-3).
     */
    private function makeSpy(): LoggerInterface
    {
        return new class implements LoggerInterface
        {
            /** @var array<int, array{level: string, message: string, context: array}> */
            public array $calls = [];

            public function emergency($message, array $context = []): void
            {
                $this->record('emergency', $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->record('alert', $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->record('critical', $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->record('error', $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->record('warning', $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->record('notice', $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->record('info', $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->record('debug', $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                $this->record(is_string($level) ? $level : '<mixed>', $message, $context);
            }

            private function record(string $level, Stringable|string $message, array $context): void
            {
                $this->calls[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Casos de aceite do W1
    |--------------------------------------------------------------------------
    */

    /**
     * CT-W1-01: "Update processed" (debug) tem o payload redigido — o texto
     * da mensagem do usuário ("paguei R$ 1500") não pode chegar ao logger.
     *
     * Formato real do Nutgram 4.x: "Update processed: {tipo}\n{payload}".
     */
    public function test_update_processed_has_payload_redacted(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $logger->debug("Update processed: message\n{\"update_id\":1,\"message\":{\"text\":\"paguei R$ 1500\"}}");

        $this->assertCount(1, $spy->calls);
        $this->assertSame('debug', $spy->calls[0]['level']);
        $this->assertSame('Update processed: message [payload redigido pelo PayloadScrubbingLogger]', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('paguei R$ 1500', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('update_id', $spy->calls[0]['message']);
    }

    /**
     * CT-W1-02: "Update failed" (error) tem o payload redigido — o texto
     * "salário R$ 5000" não pode chegar ao logger — e a exceção em
     * $context['exception'] é preservada integralmente (no Nutgram 4.x a
     * exceção não vai no corpo da mensagem, e sim no contexto).
     */
    public function test_update_failed_redacts_payload_but_preserves_exception_in_context(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $exception = new RuntimeException('boom no handler');
        $logger->error(
            "Update failed: message\n{\"update_id\":1,\"message\":{\"text\":\"salário R$ 5000\"}}",
            ['exception' => $exception],
        );

        $this->assertCount(1, $spy->calls);
        $this->assertSame('error', $spy->calls[0]['level']);
        $this->assertSame('Update failed: message [payload redigido pelo PayloadScrubbingLogger]', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('salário R$ 5000', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('update_id', $spy->calls[0]['message']);

        // Contexto preservado (incluindo a exceção — PSR-3).
        $this->assertSame(['exception' => $exception], $spy->calls[0]['context']);
    }

    /**
     * CT-W1-03: mensagens comuns do Nutgram (não-payload) são repassadas
     * inalteradas — ex.: logs de inicialização, hydrator, etc.
     */
    public function test_non_payload_nutgram_messages_pass_through_unchanged(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $logger->info('Outra mensagem qualquer do Nutgram');

        $this->assertCount(1, $spy->calls);
        $this->assertSame('Outra mensagem qualquer do Nutgram', $spy->calls[0]['message']);
    }

    /**
     * CT-W1-04: mensagens do controller/middleware do projeto (que NÃO
     * passem pelo decorator em produção, mas mesmo que passassem) são
     * repassadas inalteradas — ex.: "Telegram webhook: update recebido".
     */
    public function test_laravel_style_messages_pass_through_unchanged(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $logger->warning('Telegram webhook: update recebido', ['update_id' => 42]);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('Telegram webhook: update recebido', $spy->calls[0]['message']);
        $this->assertSame(['update_id' => 42], $spy->calls[0]['context']);
    }

    /**
     * CT-W1-05: uma mensagem com JSON no meio — mas que NÃO casa com os
     * padrões "Update processed"/"Update failed" — é repassada inalterada.
     * Importante: o decorator só atua por padrão de mensagem, não por
     * presença de JSON (caso contrário redigiria logs legítimos).
     */
    public function test_message_with_json_but_no_matching_pattern_passes_through(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $payload = 'Payload recebido: {"foo":"bar","valor":12345}';
        $logger->info($payload);

        $this->assertCount(1, $spy->calls);
        $this->assertSame($payload, $spy->calls[0]['message']);
        $this->assertStringContainsString('12345', $spy->calls[0]['message']);
    }

    /**
     * CT-W1-06: o matching é case-insensitive (defesa em profundidade —
     * mesmo que o Nutgram mude o casing no futuro).
     */
    public function test_pattern_matching_is_case_insensitive(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $logger->debug("UPDATE PROCESSED: message\n{\"secret\":42}");
        $logger->error("update failed: callback_query\n{\"secret\":99}");

        $this->assertCount(2, $spy->calls);
        $this->assertSame('UPDATE PROCESSED: message [payload redigido pelo PayloadScrubbingLogger]', $spy->calls[0]['message']);
        $this->assertSame('update failed: callback_query [payload redigido pelo PayloadScrubbingLogger]', $spy->calls[1]['message']);
        $this->assertStringNotContainsString('"secret"', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('"secret"', $spy->calls[1]['message']);
    }

    /**
     * CT-W1-07: a redação só ocorre quando há um PHP_EOL após o prefixo —
     * uma mensagem "Update processed: ..." SEM payload anexado é passada
     * inalterada (situação inesperada no Nutgram 4.x, mas defendida).
     */
    public function test_pattern_without_newline_passes_through_unchanged(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $logger->debug('Update processed sem quebra de linha');

        $this->assertCount(1, $spy->calls);
        $this->assertSame('Update processed sem quebra de linha', $spy->calls[0]['message']);
    }

    /**
     * CT-W1-08: mensagem Stringable (objeto com __toString) é normalizada
     * e tratada como string — PSR-3 aceita ambos.
     */
    public function test_stringable_message_is_handled(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $stringable = new class('Update processed: message') implements Stringable
        {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value."\n{\"text\":\"dado sensível\"}";
            }
        };

        $logger->debug($stringable);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('Update processed: message [payload redigido pelo PayloadScrubbingLogger]', $spy->calls[0]['message']);
        $this->assertStringNotContainsString('dado sensível', $spy->calls[0]['message']);
    }

    /**
     * CT-W1-09: o decorator deve casar apenas no INÍCIO da mensagem — uma
     * mensagem que CONTENHA "Update processed" no meio (ex.: um log nosso
     * explicando o comportamento) não deve ser redigida.
     */
    public function test_pattern_must_match_at_start_only(): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        // Repassa a explicar o que o decorator faz — não casa no início.
        $msg = 'Decorator payload scrubbing: casou com "Update processed"?';
        $logger->info($msg);

        $this->assertCount(1, $spy->calls);
        $this->assertSame($msg, $spy->calls[0]['message']);
    }

    /**
     * CT-W1-10 (dataProvider): TODOS os 8 níveis nomeados + log() aplicam
     * o scrub quando a mensagem casa com os padrões do Nutgram — o Nutgram
     * pode usar qualquer nível, então a defesa precisa cobrir todos.
     *
     * @param  array{method: string, level: string}  $invocation
     */
    #[DataProvider('allInterfaceMethodsProvider')]
    public function test_all_interface_methods_apply_scrub(array $invocation): void
    {
        $spy = $this->makeSpy();
        $logger = new PayloadScrubbingLogger($spy);

        $message = "Update processed: message\n{\"text\":\"secreto\"}";
        $context = ['k' => 'v'];

        // Invoca o método correspondente dinamicamente.
        if ($invocation['method'] === 'log') {
            $logger->log($invocation['level'], $message, $context);
        } else {
            $logger->{$invocation['method']}($message, $context);
        }

        $this->assertCount(1, $spy->calls, "Falhou para o método {$invocation['method']}");
        $this->assertSame(
            'Update processed: message [payload redigido pelo PayloadScrubbingLogger]',
            $spy->calls[0]['message'],
            "Payload não redigido para o método {$invocation['method']}",
        );
        $this->assertStringNotContainsString('secreto', $spy->calls[0]['message']);
        $this->assertSame($context, $spy->calls[0]['context']);
    }

    /**
     * @return array<string, array{array{method: string, level: string}}>
     */
    public static function allInterfaceMethodsProvider(): array
    {
        return [
            'emergency' => [['method' => 'emergency', 'level' => 'emergency']],
            'alert' => [['method' => 'alert', 'level' => 'alert']],
            'critical' => [['method' => 'critical', 'level' => 'critical']],
            'error' => [['method' => 'error', 'level' => 'error']],
            'warning' => [['method' => 'warning', 'level' => 'warning']],
            'notice' => [['method' => 'notice', 'level' => 'notice']],
            'info' => [['method' => 'info', 'level' => 'info']],
            'debug' => [['method' => 'debug', 'level' => 'debug']],
            'log' => [['method' => 'log', 'level' => 'debug']],
        ];
    }
}
