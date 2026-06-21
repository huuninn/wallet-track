<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Conversation\ConversationRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do {@see InMemoryBotMessenger} — fake do
 * {@see BotMessenger} usado em testes do
 * {@see ConversationRouter}.
 *
 * Foco: registro de deleções de mensagens (CT-047 fix). O array público
 * `$deletedMessages[$chatId][] = $messageId` é a fonte de asserção
 * determinística sobre quais mensagens o Router pediu para deletar.
 */
#[CoversClass(InMemoryBotMessenger::class)]
class InMemoryBotMessengerTest extends TestCase
{
    public function test_delete_message_registers_in_deleted_messages(): void
    {
        $messenger = new InMemoryBotMessenger;

        $messenger->deleteMessage('12345', 6001);

        $this->assertSame([6001], $messenger->deletedMessages['12345'] ?? []);
    }

    public function test_delete_message_accumulates_per_chat(): void
    {
        $messenger = new InMemoryBotMessenger;

        $messenger->deleteMessage('12345', 6001);
        $messenger->deleteMessage('12345', 7001);

        $this->assertSame([6001, 7001], $messenger->deletedMessages['12345'] ?? []);
    }

    public function test_delete_message_tracks_multiple_chats(): void
    {
        $messenger = new InMemoryBotMessenger;

        $messenger->deleteMessage('12345', 6001);
        $messenger->deleteMessage('12345', 7001);
        $messenger->deleteMessage('99999', 8001);

        $this->assertSame([6001, 7001], $messenger->deletedMessages['12345']);
        $this->assertSame([8001], $messenger->deletedMessages['99999']);
    }
}
