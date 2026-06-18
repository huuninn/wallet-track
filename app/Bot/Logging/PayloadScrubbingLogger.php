<?php

declare(strict_types=1);

namespace App\Bot\Logging;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Decorator PSR-3 que redige o payload bruto do update do Telegram das
 * mensagens de log emitidas pelo running mode Webhook do Nutgram.
 *
 * O Nutgram (SergiX44\Nutgram\RunningMode\Webhook::processUpdates) registra,
 * para cada update processado, uma mensagem de log com o JSON completo do
 * update recebido do Telegram:
 *
 *     // Sucesso (debug):
 *     sprintf('Update processed: %s%s%s', $tipo, PHP_EOL, $input)
 *
 *     // Falha (error) — a exceção vai em $context['exception']:
 *     sprintf('Update failed: %s%s%s', $tipo, PHP_EOL, $input)
 *
 * Onde $input é o corpo bruto da requisição HTTP (JSON do update). Num bot
 * financeiro, esse JSON contém o texto das mensagens do usuário — valores,
 * descrições de transações, categorias (PII financeira). Mesmo com o
 * controller (TelegramWebhookController) fazendo seu próprio try/catch, o
 * Nutgram já havia logado o payload ANTES de relançar a exceção.
 *
 * Este decorator é injetado APENAS no logger do Nutgram (via
 * TelegramServiceProvider); a facade Log:: do Laravel e demais pontos de
 * log do projeto não passam por aqui.
 *
 * Estratégia de redação (ver scrub()):
 * - Detecta mensagens que casam com os padrões "Update processed" ou
 *   "Update failed" (case-insensitive, no início da string).
 * - Para essas mensagens, remove TUDO a partir do primeiro PHP_EOL — o
 *   prefixo identificador (e o tipo do update, ex.: "message") é preservado;
 *   o payload é substituído por um marcador explícito de redação.
 * - Mensagens que NÃO casam com os padrões são repassadas inalteradas.
 *
 * Decisão sobre "Update failed":
 * No Nutgram 4.x atual, a exceção é entregue via $context['exception']
 * (convenção PSR-3), NÃO no corpo da mensagem. Como o decorator repassa o
 * $context inalterado, a stack trace é preservada integralmente em qualquer
 * handler PSR-3 decente (Monolog incluído). Caso versões futuras do Nutgram
 * venham a embutir a exceção na própria mensagem — situação em que a
 * separação exceção-vs-payload seria ambígua — este decorator ainda assim
 * preferirá redigir TUDO após o primeiro \n (segurança > conveniência de
 * debug), conforme ground rule 6.
 */
final class PayloadScrubbingLogger implements LoggerInterface
{
    /**
     * Marcador de redação inserido no lugar do payload, explicitando o
     * agente responsável para futura auditoria.
     */
    private const REDACTED_MARKER = ' [payload redigido pelo PayloadScrubbingLogger]';

    public function __construct(
        private readonly LoggerInterface $inner,
    ) {}

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->inner->emergency($this->scrub($message), $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->inner->alert($this->scrub($message), $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->inner->critical($this->scrub($message), $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->inner->error($this->scrub($message), $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->inner->warning($this->scrub($message), $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->inner->notice($this->scrub($message), $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->inner->info($this->scrub($message), $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->inner->debug($this->scrub($message), $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->inner->log($level, $this->scrub($message), $context);
    }

    /**
     * Redige o payload do update do Telegram quando a mensagem casa com os
     * padrões emitidos pelo Webhook::processUpdates do Nutgram.
     *
     * Mensagens que NÃO casam são devolvidas inalteradas (logs normais do
     * Nutgram e do projeto não são afetados).
     *
     * @return string A mensagem (sempre string — Stringable é normalizado).
     */
    private function scrub(Stringable|string $message): string
    {
        $message = (string) $message;

        // Padrões do Webhook::processUpdates — case-insensitive, no início.
        if (
            stripos($message, 'Update processed') === 0
            || stripos($message, 'Update failed') === 0
        ) {
            $newlinePos = strpos($message, PHP_EOL);

            if ($newlinePos !== false) {
                // Preserva o prefixo identificador (ex.: "Update processed: message")
                // e substitui o payload (a partir do primeiro PHP_EOL) por um
                // marcador de redação.
                return substr($message, 0, $newlinePos).self::REDACTED_MARKER;
            }

            // Sem PHP_EOL: não há payload anexado (situação inesperada neste
            // formato). Devolve a mensagem inalterada — nada a redigir.
            return $message;
        }

        return $message;
    }
}
