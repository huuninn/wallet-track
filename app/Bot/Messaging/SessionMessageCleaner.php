<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

/**
 * Helper centralizador para remover teclados inline das mensagens-âncora
 * (X, Y) associadas a uma sessão de conversação. Usado pelos handlers de
 * comando (/cancelar, /nova, /start) para limpar botões do chat antes de
 * transicionar.
 *
 * Z (message_id_ask_edition) é intencionalmente ignorado — é prompt de
 * texto puro sem teclado inline.
 *
 * Best-effort: falhas de remoção são capturadas internamente pelo BotMessenger.
 * Sessões legacy sem message_id_*: simplesmente no-op.
 */
final class SessionMessageCleaner
{
    public function __construct(
        private readonly BotMessenger $messenger,
    ) {}

    /**
     * Remove teclados inline das mensagens-âncora (X, Y) associadas à sessão.
     *
     * R1: qualquer teclado inline é removido após qualquer botão clicado.
     * Z (message_id_ask_edition) é ignorado — texto puro sem teclado inline.
     *
     * @param  array<string, mixed>|null  $session
     */
    public function cleanup(string $chatId, ?array $session): void
    {
        if ($session === null) {
            return;
        }

        // R1: remove teclados inline das mensagens-âncora (mantém texto
        // como histórico). Z (message_id_ask_edition) é intencionalmente
        // ignorado — é prompt de texto puro sem teclado inline.
        foreach (['message_id_confirm', 'message_id_edit_picker'] as $key) {
            $id = (int) ($session[$key] ?? 0);
            if ($id > 0) {
                $this->messenger->editMessageReplyMarkup($chatId, $id, null);
            }
        }
    }
}
