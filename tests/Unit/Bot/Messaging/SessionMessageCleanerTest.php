<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\InMemoryBotMessenger;
use App\Bot\Messaging\SessionMessageCleaner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do {@see SessionMessageCleaner} (R1/R2).
 *
 * Cobre o comportamento do helper centralizador de remoção de teclados inline
 * das mensagens-âncora (X=confirm, Y=edit_picker). Z (ask_edition) é
 * intencionalmente ignorado — texto puro sem teclado inline.
 *
 * Padrão de teste: instância direta do cleaner com {@see InMemoryBotMessenger}
 * como fake, asserções sobre o array público `$editedMarkups`.
 */
#[CoversClass(SessionMessageCleaner::class)]
class SessionMessageCleanerTest extends TestCase
{
    private InMemoryBotMessenger $messenger;

    private SessionMessageCleaner $cleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messenger = new InMemoryBotMessenger;
        $this->cleaner = new SessionMessageCleaner($this->messenger);
    }

    public function test_cleanup_with_null_session_is_noop(): void
    {
        $this->cleaner->cleanup('12345', null);

        $this->assertEmpty(
            $this->messenger->editedMarkups['12345'] ?? [],
            'cleanup com session=null não deve chamar editMessageReplyMarkup',
        );
    }

    public function test_cleanup_with_empty_session_ids_is_noop(): void
    {
        // Sessão sem message_id_* (IDs = 0 ou ausentes) → nenhum editMessageReplyMarkup.
        $session = [
            'state' => 'awaiting_confirmation',
            'draft' => ['description' => 'Teste'],
        ];

        $this->cleaner->cleanup('12345', $session);

        $this->assertEmpty(
            $this->messenger->editedMarkups['12345'] ?? [],
            'cleanup sem message_id_* populados não deve chamar editMessageReplyMarkup',
        );
    }

    public function test_cleanup_with_x_y_z_removes_markup_from_x_and_y_only(): void
    {
        // X=100, Y=200, Z=300 → markup removido em X e Y, NÃO em Z.
        $session = [
            'message_id_confirm' => 100,
            'message_id_edit_picker' => 200,
            'message_id_ask_edition' => 300,
        ];

        $this->cleaner->cleanup('12345', $session);

        $markups = $this->messenger->editedMarkups['12345'] ?? [];
        $this->assertCount(
            2,
            $markups,
            'cleanup com X, Y, Z deve chamar editMessageReplyMarkup apenas em X e Y',
        );

        $editedIds = array_column($markups, 'message_id');
        $this->assertContains(100, $editedIds, 'X deve ter marker removido');
        $this->assertContains(200, $editedIds, 'Y deve ter marker removido');
        $this->assertNotContains(300, $editedIds, 'Z NÃO deve ter marker removido (é texto puro)');

        // Ambos removidos com markup=null.
        foreach ($markups as $entry) {
            $this->assertNull($entry['markup'], 'markup deve ser null (remoção)');
        }
    }

    public function test_cleanup_with_only_x_removes_markup_once(): void
    {
        // Apenas X=100 (Y e Z ausentes ou zero) → 1 chamada editMessageReplyMarkup.
        $session = [
            'message_id_confirm' => 100,
            'message_id_edit_picker' => 0,
        ];

        $this->cleaner->cleanup('12345', $session);

        $markups = $this->messenger->editedMarkups['12345'] ?? [];
        $this->assertCount(
            1,
            $markups,
            'cleanup com apenas X deve chamar editMessageReplyMarkup 1 vez',
        );
        $this->assertSame(100, $markups[0]['message_id']);
        $this->assertNull($markups[0]['markup']);
    }

    public function test_cleanup_with_only_y_removes_markup_once(): void
    {
        // Apenas Y=200 → 1 chamada editMessageReplyMarkup.
        $session = [
            'message_id_edit_picker' => 200,
        ];

        $this->cleaner->cleanup('12345', $session);

        $markups = $this->messenger->editedMarkups['12345'] ?? [];
        $this->assertCount(
            1,
            $markups,
            'cleanup com apenas Y deve chamar editMessageReplyMarkup 1 vez',
        );
        $this->assertSame(200, $markups[0]['message_id']);
    }

    public function test_cleanup_with_only_z_is_noop(): void
    {
        // Apenas Z=300 (X e Y ausentes ou zero) → no-op.
        $session = [
            'message_id_ask_edition' => 300,
        ];

        $this->cleaner->cleanup('12345', $session);

        $this->assertEmpty(
            $this->messenger->editedMarkups['12345'] ?? [],
            'cleanup com apenas Z deve ser no-op (ignorado intencionalmente)',
        );
    }

    public function test_cleanup_tracks_multiple_chats_independently(): void
    {
        // Remoções em múltiplos chats são independentes.
        $sessionA = ['message_id_confirm' => 100];
        $sessionB = ['message_id_confirm' => 500];

        $this->cleaner->cleanup('chat-A', $sessionA);
        $this->cleaner->cleanup('chat-B', $sessionB);

        $markupsA = $this->messenger->editedMarkups['chat-A'] ?? [];
        $markupsB = $this->messenger->editedMarkups['chat-B'] ?? [];

        $this->assertCount(1, $markupsA);
        $this->assertSame(100, $markupsA[0]['message_id']);

        $this->assertCount(1, $markupsB);
        $this->assertSame(500, $markupsB[0]['message_id']);
    }
}
