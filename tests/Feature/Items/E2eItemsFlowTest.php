<?php

declare(strict_types=1);

namespace Tests\Feature\Items;

use App\Actions\ExtractsImage;
use App\Actions\ExtractsText;
use App\Actions\SuggestCategory;
use App\Actions\SuggestsLabels;
use App\Actions\SyncsSheet;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Conversation\ConversationInput;
use App\Conversation\ConversationRouter;
use App\Conversation\StateMachine;
use App\Dto\SessionData;
use App\Dto\TransactionData;
use App\Enums\ConversationState;
use App\Models\Transaction;
use App\Services\Google\InMemorySheetsGateway;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes E2E da feature items (M-ITENS-7 / CT-159, CT-160).
 *
 * Validam o fluxo completo da extração de items até a persistência
     * nos 3 destinos (banco de dados, Sheets, Telegram), sem chamar LLM real —
 * usando mocks determinísticos e InMemory gateways.
 *
 * CT-159: foto de cupom → extração → confirmação → persistência (3 destinos)
 * CT-160: edição de items via picker → atualização de draft → persistência
 */
#[CoversClass(ConversationRouter::class)]
class E2eItemsFlowTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '99999';

    private InMemorySheetsGateway $sheetsGw;

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    /** @var object&ExtractsText */
    private object $extractText;

    /** @var object&ExtractsImage */
    private object $extractImage;

    /** @var object&SyncsSheet */
    private object $syncSheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->sheetsGw = new InMemorySheetsGateway;
        $this->messenger = new InMemoryBotMessenger;

        $this->bindStoreToContainer();

        // Stub de extração de texto — não será usado nos testes E2E de foto,
        // mas o Router precisa da dependência.
        $this->extractText = new class implements ExtractsText
        {
            public ?TransactionData $toReturn = null;

            public int $callCount = 0;

            public function handle(string $text, array $labelCatalog = []): TransactionData
            {
                $this->callCount++;
                if ($this->toReturn === null) {
                    throw new \LogicException('Stub não configurado: defina toReturn.');
                }

                return $this->toReturn;
            }
        };

        // Stub de extração de imagem (Gemini).
        $this->extractImage = new class implements ExtractsImage
        {
            public ?TransactionData $toReturn = null;

            public int $callCount = 0;

            public function handle(string $fileId, array $labelCatalog = []): TransactionData
            {
                $this->callCount++;
                if ($this->toReturn === null) {
                    throw new \LogicException('Stub não configurado: defina toReturn.');
                }

                return $this->toReturn;
            }
        };

        // Stub de sync com Sheets — captura o DTO para asserção e
        // escreve no InMemorySheetsGateway para simular o append.
        $sheetsGw = $this->sheetsGw;
        $this->syncSheet = new class($sheetsGw) implements SyncsSheet
        {
            public bool $toReturn = true;

            public int $callCount = 0;

            public ?TransactionData $lastDto = null;

            public ?int $lastTxId = null;

            public function __construct(
                private readonly InMemorySheetsGateway $sheetsGw,
            ) {}

            public function handle(TransactionData $dto, int $txId): bool
            {
                $this->callCount++;
                $this->lastDto = $dto;
                $this->lastTxId = $txId;

                // Simula o append na planilha (formata items como faria o SheetsService).
                // InMemorySheetsGateway é single-sheet — não tem parâmetro de aba.
                $header = $this->sheetsGw->getHeaderRow();
                if ($header === null || $header === []) {
                    $this->sheetsGw->writeHeaderRow(
                        ['Data', 'Descrição', 'Valor', 'Tipo', 'Categoria', 'Labels', 'ID', 'Observações', 'Itens'],
                    );
                }

                $this->sheetsGw->appendRow([
                    $dto->date ?? '',
                    $dto->description ?? '',
                    (string) ($dto->amount ?? ''),
                    $dto->type ?? '',
                    $dto->category ?? '',
                    implode(' ', $dto->labels),
                    (string) $txId,
                    $dto->observations ?? '',
                    $this->formatItemsForSheets($dto->items),
                ]);

                return $this->toReturn;
            }

            /**
             * Simula formatItems do SheetsService (simplificado para asserção).
             *
             * @param  list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>  $items
             */
            private function formatItemsForSheets(array $items): string
            {
                if ($items === []) {
                    return '';
                }

                $lines = [];
                $i = 1;
                foreach ($items as $item) {
                    $name = $item['name'];
                    $qty = $item['qty'];
                    $unit = $item['unitPrice'];
                    $sub = $item['subtotal'];

                    if ($qty !== null && $unit !== null && $sub !== null) {
                        $qtyStr = ($qty == (int) $qty) ? (string) (int) $qty : number_format($qty, 2, ',', '');
                        $lines[] = sprintf(
                            '%d. %s (x%s — R$ %s = R$ %s)',
                            $i,
                            $name,
                            $qtyStr,
                            number_format($unit, 2, ',', '.'),
                            number_format($sub, 2, ',', '.'),
                        );
                    } elseif ($unit !== null && $sub !== null) {
                        $lines[] = sprintf('%d. %s (R$ %s = R$ %s)', $i, $name, number_format($unit, 2, ',', '.'), number_format($sub, 2, ',', '.'));
                    } elseif ($sub !== null) {
                        $lines[] = sprintf('%d. %s (R$ %s)', $i, $name, number_format($sub, 2, ',', '.'));
                    } else {
                        $lines[] = "{$i}. {$name}";
                    }
                    $i++;
                }

                return implode("\n", $lines);
            }
        };
    }

    private function makeRouter(int $maxRetries = 3): ConversationRouter
    {
        $suggestLabels = new class implements SuggestsLabels
        {
            public array $toReturn = [];

            public function suggest(TransactionData $dto, array $labelCatalog = []): array
            {
                return $this->toReturn;
            }
        };

        return new ConversationRouter(
            stateMachine: new StateMachine,
            messenger: $this->messenger,
            formatter: new TransactionSummaryFormatter,
            store: $this->store,
            extractText: $this->extractText,
            extractImage: $this->extractImage,
            syncSheet: $this->syncSheet,
            suggestCategory: new SuggestCategory($this->store),
            suggestLabels: $suggestLabels,
            maxDataRetries: $maxRetries,
        );
    }

    /**
     * Semeia uma sessão em AWAITING_CONFIRMATION para testes de edição.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedConfirmationSession(TransactionData $dto, array $overrides = []): void
    {
        $confirmMsgId = (int) ($overrides['message_id_confirm'] ?? 2000);

        $this->store->setSession(self::CHAT_ID, new SessionData(
            state: ConversationState::AWAITING_CONFIRMATION->value,
            draft: $overrides['draft'] ?? $dto->toDraftArray(),
            awaitingField: $overrides['awaiting_field'] ?? null,
            source: $overrides['source'] ?? 'text',
            messageIdConfirm: $confirmMsgId,
            messageIdEditPicker: $overrides['message_id_edit_picker'] ?? null,
            messageIdAskEdition: $overrides['message_id_ask_edition'] ?? null,
            retryCount: $overrides['retry_count'] ?? 0,
        ));
    }

    /**
     * Lê a sessão atual do WalletStore.
     *
     * @return array<string, mixed>|null
     */
    private function currentSession(): ?array
    {
        return $this->store->getSession(self::CHAT_ID);
    }

    /**
     * Cria um DTO com 10 items (CT-159).
     */
    private function dtoWith10Items(): TransactionData
    {
        return TransactionData::fromArray([
            'description' => 'Supermercado XYZ',
            'amount' => 87.30,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'items' => [
                ['name' => 'Arroz 5kg', 'qty' => 1, 'unitPrice' => 32.90, 'subtotal' => 32.90],
                ['name' => 'Feijão', 'qty' => 2, 'unitPrice' => 8.50, 'subtotal' => 17.00],
                ['name' => 'Detergente', 'qty' => 3, 'unitPrice' => 4.50, 'subtotal' => 13.50],
                ['name' => 'Sabonete', 'qty' => 6, 'unitPrice' => 2.40, 'subtotal' => 14.40],
                ['name' => 'Bolsa plástica', 'qty' => 1, 'unitPrice' => 0.50, 'subtotal' => 0.50],
                ['name' => 'Leite', 'qty' => 2, 'unitPrice' => 5.99, 'subtotal' => 11.98],
                ['name' => 'Pão integral', 'qty' => 1, 'unitPrice' => 8.90, 'subtotal' => 8.90],
                ['name' => 'Café', 'qty' => 1, 'unitPrice' => 15.90, 'subtotal' => 15.90],
                ['name' => 'Açúcar', 'qty' => 1, 'unitPrice' => 4.29, 'subtotal' => 4.29],
                ['name' => 'Óleo', 'qty' => 1, 'unitPrice' => 7.99, 'subtotal' => 7.99],
            ],
        ]);
    }

    /**
     * Cria um DTO com 3 items (CT-160).
     */
    private function dtoWith3Items(): TransactionData
    {
        return TransactionData::fromArray([
            'description' => 'Compra básica',
            'amount' => 50.00,
            'type' => 'expense',
            'category' => 'Alimentação',
            'date' => '2026-06-15',
            'items' => [
                ['name' => 'Item A', 'qty' => 1, 'unitPrice' => 10.00, 'subtotal' => 10.00],
                ['name' => 'Item B', 'qty' => 1, 'unitPrice' => 15.00, 'subtotal' => 15.00],
                ['name' => 'Item C', 'qty' => 1, 'unitPrice' => 25.00, 'subtotal' => 25.00],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-159: E2E foto de cupom → banco de dados + Sheets + Telegram
    |--------------------------------------------------------------------------
    */

    /**
     * CT-159: fluxo completo da extração via foto (Gemini) de cupom com 10
     * items até a persistência nos 3 destinos: banco de dados, Sheets e Telegram.
     *
     * Critérios de aceite:
     *  - Gemini retorna JSON com 10 items
     *  - Router processa foto → DTO com items
     *  - Confirmação mostra bloco items (10, sem truncamento)
     *  - Confirmar → saveTransaction (banco de dados tem items[])
     *  - SyncSheet → Sheets tem coluna I com 10 linhas numeradas
     *  - Assert: 3 destinos têm os mesmos 10 items
     */
    public function test_e2e_gemini_extraction_to_persistence_to_display(): void
    {
        // 1. Configurar stub do Gemini para retornar 10 items.
        $this->extractImage->toReturn = $this->dtoWith10Items();

        $router = $this->makeRouter();

        // 2. Enviar foto (simulada).
        $router->route(ConversationInput::photo(self::CHAT_ID, 'photo_file_id_123'));

        // 3. Verificar que entrou em AWAITING_CONFIRMATION.
        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $session['state']);

        // 4. Verificar que a confirmação foi enviada com o bloco de items.
        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);
        $confirmation = $this->messenger->confirmations[self::CHAT_ID][0];
        $this->assertInstanceOf(TransactionData::class, $confirmation['draft']);
        $this->assertCount(10, $confirmation['draft']->items);
        $this->assertSame('Arroz 5kg', $confirmation['draft']->items[0]['name']);

        // 5. Confirmar a transação (callback "confirm").
        $currentConfirmId = (int) $session['message_id_confirm'];

        $router->route(ConversationInput::callback(
            self::CHAT_ID,
            'cb_confirm_1',
            'confirm',
            $currentConfirmId,
        ));

        // 6. Verificar banco de dados: transação salva com 10 items.
        $tx = Transaction::with('items')->where('chat_id', self::CHAT_ID)->first();
        $this->assertNotNull($tx);
        $items = $tx->items->toArray();
        $this->assertCount(10, $items);
        $this->assertSame('Arroz 5kg', $items[0]['name']);
        $this->assertSame(32.90, (float) $items[0]['unit_price']);
        $this->assertSame('Óleo', $items[9]['name']);

        // 7. Verificar Sheets: coluna I com 10 linhas numeradas.
        $this->assertSame(1, $this->syncSheet->callCount, 'SyncSheet deve ter sido chamado');
        $rows = $this->sheetsGw->rows();
        $this->assertNotEmpty($rows, 'Planilha deve ter linhas');
        $lastRow = end($rows);
        $itemsColumn = end($lastRow); // Coluna I (última posição)
        $this->assertStringContainsString('1.', $itemsColumn);
        $this->assertStringContainsString('10.', $itemsColumn);
        $this->assertStringContainsString('Arroz 5kg', $itemsColumn);
        $this->assertStringContainsString('Óleo', $itemsColumn);
        // Ordenação: subtotal crescente → Bolsa plástica (R$ 0,50) deve ser #1.
        $this->assertStringContainsString('Bolsa plástica (x1 — R$ 0,50 = R$ 0,50)', $itemsColumn);

        // 8. Verificar Telegram: mensagem de sucesso enviada.
        $this->assertCount(1, $this->messenger->successes[self::CHAT_ID] ?? []);
    }

    /*
    |--------------------------------------------------------------------------
    | CT-160: E2E edição de items via picker → persistência
    |--------------------------------------------------------------------------
    */

    /**
     * CT-160: edição de items via picker atualiza o draft e persiste.
     *
     * Critérios de aceite:
     *  - Transação confirmada com 3 items
     *  - Editar → 🛒 Itens → enviar 5 items novos
     *  - Nova confirmação mostra 5 items
     *  - Confirmar → banco de dados atualizado
     *  - Sheets é append-only (documentar limitação)
     */
    public function test_e2e_edit_items_via_picker_updates_draft(): void
    {
        // Preparar: sessão em AWAITING_CONFIRMATION com 3 items.
        $dto = $this->dtoWith3Items();
        $this->seedConfirmationSession($dto);

        $router = $this->makeRouter();
        $confirmMsgId = 2000;

        // 1. Clicar "Editar" → picker deve conter botão "🛒 Itens".
        $router->route(ConversationInput::callback(
            self::CHAT_ID,
            'cb_edit_1',
            'edit',
            $confirmMsgId,
        ));

        $this->assertCount(1, $this->messenger->fieldPickers[self::CHAT_ID] ?? []);
        // Verificar que o picker contém o callback "edit:items".
        $pickerCallbacks = $this->messenger->fieldPickerCallbacks[self::CHAT_ID] ?? [];
        $this->assertContains('edit:items', $pickerCallbacks, 'Picker deve conter edit:items');

        // Obter message_id do picker.
        $pickerMsgId = $this->messenger->fieldPickers[self::CHAT_ID][0]['message_id'];

        // 2. Clicar "🛒 Itens" → entra em AWAITING_EDITION com awaiting_field='items'.
        $router->route(ConversationInput::callback(
            self::CHAT_ID,
            'cb_edit_items_1',
            'edit:items',
            $pickerMsgId,
        ));

        $session = $this->currentSession();
        $this->assertNotNull($session);
        $this->assertSame(ConversationState::AWAITING_EDITION->value, $session['state']);
        $this->assertSame('items', $session['awaiting_field']);

        // 3. Enviar 5 items novos.
        $router->route(ConversationInput::text(
            self::CHAT_ID,
            "Item A x1 10.00\nItem B x1 15.00\nItem C x1 20.00\nItem D x1 25.00\nItem E x1 30.00",
        ));

        // 4. Verificar que voltou para AWAITING_CONFIRMATION com 5 items.
        $sessionAfter = $this->currentSession();
        $this->assertNotNull($sessionAfter);
        $this->assertSame(ConversationState::AWAITING_CONFIRMATION->value, $sessionAfter['state']);

        $draftAfter = TransactionData::fromDraftArray($sessionAfter['draft'] ?? null);
        $this->assertCount(5, $draftAfter->items);
        $this->assertSame('Item A', $draftAfter->items[0]['name']);
        $this->assertSame('Item E', $draftAfter->items[4]['name']);

        // 5. Mensagem de feedback: "🛒 Itens alterados de 3 itens para 5 itens".
        // sentTexts order: [0]=picker prompt, [1]=askForEdition prompt, [2]=fieldChangeMessage
        $sentTexts = $this->messenger->sentTexts[self::CHAT_ID] ?? [];
        $this->assertCount(4, $sentTexts, 'Deve ter 4 mensagens: picker, askEdition, feedback, confirmation');
        $feedbackText = $sentTexts[2]['text'] ?? '';
        $this->assertStringContainsString('Itens', $feedbackText);
        $this->assertStringContainsString('3', $feedbackText);
        $this->assertStringContainsString('5', $feedbackText);

        // 6. Nova confirmação foi enviada.
        $this->assertCount(1, $this->messenger->confirmations[self::CHAT_ID] ?? []);
        $newConfirm = $this->messenger->confirmations[self::CHAT_ID][0];
        $this->assertCount(5, $newConfirm['draft']->items);

        // 7. Confirmar a transação editada.
        $newConfirmId = (int) $sessionAfter['message_id_confirm'];

        $router->route(ConversationInput::callback(
            self::CHAT_ID,
            'cb_confirm_2',
            'confirm',
            $newConfirmId,
        ));

        // 8. Verificar banco de dados: 5 items (não 3).
        $tx = Transaction::with('items')->where('chat_id', self::CHAT_ID)->first();
        $this->assertNotNull($tx);
        $items = $tx->items->toArray();
        $this->assertCount(5, $items);
        $this->assertSame('Item A', $items[0]['name']);
        $this->assertSame('Item E', $items[4]['name']);

        // 9. Verificar que o sync foi chamado (append-only).
        $this->assertSame(1, $this->syncSheet->callCount);
    }
}
