<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\CategoriasHandler;
use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\InMemoryBotMessenger;
use App\Dto\SessionData;
use App\Enums\ConversationState;
use App\Models\Category;
use App\Services\Store\WalletStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\Support\WithWalletStore;
use Tests\TestCase;

/**
 * Testes do {@see CategoriasHandler} (M9.6 / T-007).
 *
 * Cobertura:
 *  - CT-029      → lista completa com use_count
 *  - CT-029a     → só defaults (9 categorias)
 *  - CT-029b     → com personalizadas
 *  - CT-029c     → contador de uso plural/singular
 *  - CT-029d     → 0 personalizadas
 *  - CT-029f     → preserva estado não-IDLE
 */
#[CoversClass(CategoriasHandler::class)]
class CategoriasHandlerTest extends TestCase
{
    use RefreshDatabase;
    use WithWalletStore;

    private const string CHAT_ID = '12345';

    private const int CHAT_ID_INT = 12345;

    private WalletStore $store;

    private InMemoryBotMessenger $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWalletStore();

        $this->messenger = new InMemoryBotMessenger;

        $this->bindStoreToContainer();
        $this->app->instance(BotMessenger::class, $this->messenger);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeBotMock(): Nutgram
    {
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = self::CHAT_ID_INT;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->andReturn($message);

        return $bot;
    }

    /**
     * Popula a tabela `categories` com a configuração dada.
     *
     * @param  list<array{display_name: string, use_count?: int, is_default?: bool}>  $categories
     */
    private function seedCategories(array $categories): void
    {
        foreach ($categories as $cat) {
            $name = mb_strtolower($cat['display_name']);
            Category::factory()->create([
                'slug' => $name,
                'display_name' => $cat['display_name'],
                'default_type' => 'expense',
                'use_count' => (int) ($cat['use_count'] ?? 0),
                'is_default' => (bool) ($cat['is_default'] ?? false),
            ]);
        }
    }

    #[Group('smoke')]
    public function test_categorias_lists_all_with_use_count(): void
    {
        // CT-029 / CT-029c: lista com contador de uso.
        $this->seedCategories([
            ['display_name' => 'Alimentação', 'use_count' => 5, 'is_default' => true],
            ['display_name' => 'Transporte', 'use_count' => 3, 'is_default' => true],
            ['display_name' => 'Pet', 'use_count' => 1, 'is_default' => false],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('📊', $text);
        $this->assertStringContainsString('<b>Categorias</b>', $text);
        $this->assertStringContainsString('Alimentação', $text);
        $this->assertStringContainsString('5 transações', $text);
        $this->assertStringContainsString('Transporte', $text);
        $this->assertStringContainsString('3 transações', $text);
        $this->assertStringContainsString('Pet', $text);
        $this->assertStringContainsString('1 transação', $text);
    }

    public function test_categorias_only_defaults(): void
    {
        // CT-029a: só 9 categorias padrão.
        $this->seedCategories([
            ['display_name' => 'Alimentação', 'is_default' => true],
            ['display_name' => 'Transporte', 'is_default' => true],
            ['display_name' => 'Moradia', 'is_default' => true],
            ['display_name' => 'Saúde', 'is_default' => true],
            ['display_name' => 'Educação', 'is_default' => true],
            ['display_name' => 'Lazer', 'is_default' => true],
            ['display_name' => 'Salário', 'is_default' => true],
            ['display_name' => 'Freelance', 'is_default' => true],
            ['display_name' => 'Outros', 'is_default' => true],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        foreach (['Alimentação', 'Transporte', 'Moradia', 'Saúde', 'Educação', 'Lazer', 'Salário', 'Freelance', 'Outros'] as $name) {
            $this->assertStringContainsString($name, $text);
        }
        $this->assertStringContainsString('0 transações', $text);
    }

    public function test_categorias_with_custom(): void
    {
        // CT-029b: padrão + personalizadas.
        $this->seedCategories([
            ['display_name' => 'Alimentação', 'is_default' => true],
            ['display_name' => 'Pet', 'is_default' => false],
            ['display_name' => 'Hobbies', 'is_default' => false],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('Alimentação', $text);
        $this->assertStringContainsString('Pet', $text);
        $this->assertStringContainsString('Hobbies', $text);
    }

    public function test_categorias_uses_fallback_emoji_for_unknown(): void
    {
        // Categoria desconhecida → 🏷.
        $this->seedCategories([
            ['display_name' => 'Categoria Exótica', 'is_default' => false],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('🏷', $text);
    }

    public function test_categorias_sorted_by_use_count_desc(): void
    {
        // Ordem: use_count DESC, display_name ASC (spec §1.7).
        $this->seedCategories([
            ['display_name' => 'Alimentação', 'use_count' => 1],
            ['display_name' => 'Transporte', 'use_count' => 5],
            ['display_name' => 'Moradia', 'use_count' => 3],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $posTransp = mb_strpos($text, 'Transporte');
        $posMoradia = mb_strpos($text, 'Moradia');
        $posAlim = mb_strpos($text, 'Alimentação');

        $this->assertNotFalse($posTransp);
        $this->assertNotFalse($posMoradia);
        $this->assertNotFalse($posAlim);
        $this->assertLessThan($posMoradia, $posTransp, 'Transporte (5) antes de Moradia (3)');
        $this->assertLessThan($posAlim, $posMoradia, 'Moradia (3) antes de Alimentação (1)');
    }

    public function test_categorias_singular_count_uses_singular_label(): void
    {
        // "1 transação" (singular) vs "2 transações" (plural).
        $this->seedCategories([
            ['display_name' => 'Alimentação', 'use_count' => 1],
            ['display_name' => 'Transporte', 'use_count' => 2],
        ]);

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $text = $this->messenger->sentTexts[self::CHAT_ID][0]['text'];
        $this->assertStringContainsString('1 transação', $text);
        $this->assertStringContainsString('2 transações', $text);
    }

    public function test_categorias_preserves_non_idle_state(): void
    {
        // CT-029f: handler é stateless — não toca a sessão.
        $this->seedCategories([['display_name' => 'Outros', 'is_default' => true]]);

        $this->store->setSession(self::CHAT_ID, new SessionData(
            state: ConversationState::AWAITING_DATA->value,
            awaitingField: 'amount',
            draft: ['description' => 'Almoço'],
        ));

        $bot = $this->makeBotMock();
        (new CategoriasHandler)($bot);

        $session = $this->store->getSession(self::CHAT_ID);
        $this->assertNotNull($session, 'Sessão deve permanecer após /categorias (CT-029f)');
        $this->assertSame('awaiting_data', $session['state']);
        $this->assertSame('amount', $session['awaiting_field']);

        $this->assertCount(1, $this->messenger->sentTexts[self::CHAT_ID] ?? []);
    }
}
