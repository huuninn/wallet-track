<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\InMemoryBotMessenger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes da implementação fake do BotMessenger.
 *
 * Cobre:
 *  - CT-124 / AC-016: picker inclui botão 🛒 Itens (callback edit:items).
 */
#[CoversClass(InMemoryBotMessenger::class)]
class InMemoryBotMessengerTest extends TestCase
{
    private InMemoryBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->messenger = new InMemoryBotMessenger;
    }

    public function test_edit_field_picker_includes_items_button(): void
    {
        // CT-124 / AC-016: picker tem botão com callback_data: 'edit:items'.
        $chatId = 12345;
        $this->messenger->sendEditFieldPicker($chatId);

        $this->assertArrayHasKey($chatId, $this->messenger->fieldPickerCallbacks);
        $this->assertContains('edit:items', $this->messenger->fieldPickerCallbacks[$chatId]);
    }

    public function test_edit_field_picker_includes_all_standard_buttons(): void
    {
        // Verifica que todos os botões padrão estão presentes.
        $chatId = 67890;
        $this->messenger->sendEditFieldPicker($chatId);

        $callbacks = $this->messenger->fieldPickerCallbacks[$chatId];

        $expected = [
            'edit:amount',
            'edit:type',
            'edit:date',
            'edit:description',
            'edit:category',
            'edit:observations',
            'edit:items',
        ];

        foreach ($expected as $callback) {
            $this->assertContains($callback, $callbacks, "Picker deve incluir callback: {$callback}");
        }
    }
}
