<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Dto\TransactionData;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do {@see TransactionSummaryFormatter} (M9.5 / T-005).
 *
 * Foco nos métodos novos: {@see TransactionSummaryFormatter::listSummary()}
 * e (indiretamente) {@see TransactionSummaryFormatter::listRow()} (privado,
 * exercitado via listSummary).
 *
 * Cobertura mapeada para o plano de testes M9:
 *  - CT-027 / CT-027a / CT-027b / CT-027c — formato da listagem
 *  - CT-028g                              — verificação visual do formato
 */
#[CoversClass(TransactionSummaryFormatter::class)]
class TransactionSummaryFormatterTest extends TestCase
{
    private TransactionSummaryFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new TransactionSummaryFormatter;
    }

    /**
     * Cria uma Collection de instâncias Transaction preenchidas com dados
     * de teste, sem tocar o banco de dados.
     *
     * As instâncias são "descaracterizadas": nunca persistidas, sem eventos
     * de saving/observers/ciclo de vida Eloquent — servem como value-objects
     * para alimentar listRow(), que apenas lê propriedades e a relação labels.
     *
     * Usamos setRawAttributes para que valores brutos contornem o caminho
     * de escrita dos casts (ex.: immutable_date → fromDateTime() → DB
     * connection). Na leitura, isStandardDateFormat('YYYY-MM-DD') bate
     * antes de getDateFormat(), então strings ISO rodam sem DB.
     *
     * @param  list<array<string, mixed>>  $dataList
     * @return Collection<int, Transaction>
     */
    private function makeTransactions(array $dataList): Collection
    {
        $transactions = [];
        foreach ($dataList as $i => $data) {
            $tx = new Transaction();

            $tx->setRawAttributes([
                'id' => $i + 1,
                'description' => $data['description'] ?? '',
                'amount' => $data['amount'] ?? 0.0,
                'type' => $data['type'] ?? '',
                'date' => $data['date'] ?? null,
            ]);

            // Categoria: ghost model (não persistido) para a relação BelongsTo.
            $categoryName = $data['category'] ?? null;
            if ($categoryName !== null && $categoryName !== '') {
                $category = new Category();
                $category->setRawAttributes([
                    'id' => $i + 1,
                    'display_name' => $categoryName,
                    'slug' => mb_strtolower($categoryName),
                ]);
                $tx->setRelation('category', $category);
            } else {
                $tx->setRelation('category', null);
            }

            $labelNames = $data['labels'] ?? [];
            $tx->setRelation('labels', collect(array_map(
                fn(string $name): array => ['name' => $name],
                $labelNames,
            )));

            $transactions[] = $tx;
        }

        return collect($transactions);
    }

    public function test_list_summary_with_multiple_transactions(): void
    {
        // CT-027 / CT-028g: listagem com 3 transações, em PT-BR com emojis.
        $transactions = $this->makeTransactions([
            [
                'description' => 'Almoço no restaurante',
                'amount' => 47.50,
                'type' => 'expense',
                'category' => 'Alimentação',
                'date' => '2026-06-15',
                'labels' => ['almoco', 'restaurante'],
            ],
            [
                'description' => 'Salário Junho',
                'amount' => 5000.00,
                'type' => 'income',
                'category' => 'Salário',
                'date' => '2026-06-01',
                'labels' => [],
            ],
            [
                'description' => 'Uber para o trabalho',
                'amount' => 23.90,
                'type' => 'expense',
                'category' => 'Transporte',
                'date' => '2026-06-12',
                'labels' => ['trabalho'],
            ],
        ]);

        $out = $this->formatter->listSummary($transactions, 3);

        // Cabeçalho (CT-027): contagem no plural.
        $this->assertStringContainsString('📋', $out);
        $this->assertStringContainsString('<b>Últimas 3 transações</b>', $out);

        // Linha 1 — categoria Alimentação (CT-028g).
        $this->assertStringContainsString('1.', $out);
        $this->assertStringContainsString('🍕', $out);
        $this->assertStringContainsString('<b>Almoço no restaurante</b>', $out);
        $this->assertStringContainsString('Despesa', $out);
        $this->assertStringContainsString('R$ 47,50', $out);
        $this->assertStringContainsString('#almoco', $out);
        $this->assertStringContainsString('#restaurante', $out);
        $this->assertStringContainsString('15/06/2026', $out);

        // Linha 2 — Receita.
        $this->assertStringContainsString('2.', $out);
        $this->assertStringContainsString('💰', $out);
        $this->assertStringContainsString('<b>Salário Junho</b>', $out);
        $this->assertStringContainsString('Receita', $out);
        $this->assertStringContainsString('R$ 5.000,00', $out);
        $this->assertStringContainsString('01/06/2026', $out);

        // Linha 3 — Transporte.
        $this->assertStringContainsString('3.', $out);
        $this->assertStringContainsString('🚗', $out);

        // Rodapé (CT-027).
        $this->assertStringContainsString('<i>Mostrando 3 transações.</i>', $out);
    }

    public function test_list_summary_empty_returns_friendly_message(): void
    {
        // CT-027a: lista vazia → apenas cabeçalho com contagem zero.
        $out = $this->formatter->listSummary(collect([]), 0);

        $this->assertStringContainsString('Últimas 0 transações', $out);
        // Sem linhas numeradas.
        $this->assertStringNotContainsString('1.', $out);
    }

    public function test_list_summary_with_only_income(): void
    {
        // CT-027b: só receitas — todas marcadas com 💰.
        $transactions = $this->makeTransactions([
            ['description' => 'Salário', 'amount' => 5000.0, 'type' => 'income', 'category' => 'Salário', 'date' => '2026-06-01'],
            ['description' => 'Freela', 'amount' => 1200.0, 'type' => 'income', 'category' => 'Freelance', 'date' => '2026-06-10'],
        ]);

        $out = $this->formatter->listSummary($transactions, 2);

        $this->assertStringContainsString('Receita', $out);
        $this->assertStringContainsString('💰', $out);
        $this->assertStringNotContainsString('Despesa', $out);
    }

    public function test_list_summary_with_only_expense(): void
    {
        // CT-027c: só despesas.
        $transactions = $this->makeTransactions([
            ['description' => 'Mercado', 'amount' => 250.0, 'type' => 'expense', 'category' => 'Alimentação', 'date' => '2026-06-05'],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        $this->assertStringContainsString('Despesa', $out);
        $this->assertStringContainsString('💸', $out);
    }

    public function test_list_summary_singular_count_uses_singular_label(): void
    {
        $transactions = $this->makeTransactions([
            ['description' => 'Cinema', 'amount' => 35.0, 'type' => 'expense', 'category' => 'Lazer', 'date' => '2026-06-15'],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        $this->assertStringContainsString('<b>Últimas 1 transação</b>', $out);
        $this->assertStringContainsString('<i>Mostrando 1 transação.</i>', $out);
    }

    public function test_list_summary_uses_fallback_emoji_for_unknown_category(): void
    {
        // Categoria personalizada → 🏷.
        $transactions = $this->makeTransactions([
            ['description' => 'Pet shop', 'amount' => 80.0, 'type' => 'expense', 'category' => 'Pet', 'date' => '2026-06-10'],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        $this->assertStringContainsString('🏷', $out);
    }

    public function test_list_summary_escapes_html_in_description(): void
    {
        // Defesa contra HTML injection via descrição com `<script>`.
        $transactions = $this->makeTransactions([
            [
                'description' => '<script>alert(1)</script>',
                'amount' => 10.0,
                'type' => 'expense',
                'category' => 'Outros',
                'date' => '2026-06-15',
            ],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_list_summary_omits_labels_section_when_empty(): void
    {
        // Sem labels → terceira linha tem só a data, sem separador " · #".
        $transactions = $this->makeTransactions([
            ['description' => 'X', 'amount' => 10.0, 'type' => 'expense', 'category' => 'Outros', 'date' => '2026-06-15', 'labels' => []],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        // A linha da data não deve terminar com " ·" ou " · ".
        $this->assertDoesNotMatchRegularExpression('/15\/06\/2026 · $/m', $out);
    }

    public function test_list_summary_with_max_cap_50(): void
    {
        // CT-028d: listagem com 50 transações (cap do /ultimos).
        $data = [];
        for ($i = 1; $i <= 50; $i++) {
            $data[] = [
                'description' => "Tx {$i}",
                'amount' => (float) $i,
                'type' => 'expense',
                'category' => 'Outros',
                'date' => '2026-06-15',
            ];
        }

        $out = $this->formatter->listSummary($this->makeTransactions($data), 50);

        $this->assertStringContainsString('<b>Últimas 50 transações</b>', $out);
        $this->assertStringContainsString('1.', $out);
        $this->assertStringContainsString('50.', $out);
        $this->assertStringContainsString('<i>Mostrando 50 transações.</i>', $out);
    }

    /*
    |--------------------------------------------------------------------------
    | Testes do método summary() — confirmação/resumo de transação única
    |--------------------------------------------------------------------------
    */

    public function test_summary_includes_observations_when_present(): void
    {
        $dto = new TransactionData(
            description: 'Almoço no restaurante',
            amount: 47.50,
            type: 'expense',
            category: 'Alimentação',
            date: '2026-06-15',
            observations: 'Cliente: João, mesa 5',
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('📝 <b>Observações:</b>', $out);
        $this->assertStringContainsString('Cliente: João, mesa 5', $out);
        // Observações devem aparecer antes da Data (nova ordem: obs → items → data).
        $this->assertStringContainsString("📝 <b>Observações:</b> Cliente: João, mesa 5\n📅 <b>Data:</b> 15/06/2026", $out);
    }

    public function test_summary_omits_observations_when_null(): void
    {
        $dto = new TransactionData(
            description: 'Uber',
            amount: 23.90,
            type: 'expense',
            category: 'Transporte',
            date: '2026-06-15',
            observations: null,
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('Observações', $out);
    }

    public function test_summary_omits_observations_when_empty_string(): void
    {
        $dto = new TransactionData(
            description: 'Cinema',
            amount: 35.0,
            type: 'expense',
            category: 'Lazer',
            date: '2026-06-15',
            observations: '',
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('Observações', $out);
    }

    public function test_summary_escapes_html_in_observations(): void
    {
        $dto = new TransactionData(
            description: 'Teste',
            amount: 10.0,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            observations: '<script>alert(1)</script>',
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    /*
    |--------------------------------------------------------------------------
    | M1 (R1/R2) — fieldChangeMessage
    |--------------------------------------------------------------------------
    */

    public function test_field_change_message_for_amount(): void
    {
        $msg = $this->formatter->fieldChangeMessage('amount', 47.50, 100.00);

        $this->assertStringContainsString('💵', $msg);
        $this->assertStringContainsString('Valor', $msg);
        $this->assertStringContainsString('alterado', $msg);
        $this->assertStringContainsString('R$ 47,50', $msg);
        $this->assertStringContainsString('R$ 100,00', $msg);
        $this->assertStringContainsString('de', $msg);
        $this->assertStringContainsString('para', $msg);
    }

    public function test_field_change_message_for_type(): void
    {
        $msg = $this->formatter->fieldChangeMessage('type', 'expense', 'income');

        $this->assertStringContainsString('🔖', $msg);
        $this->assertStringContainsString('Tipo', $msg);
        $this->assertStringContainsString('alterado', $msg);
        $this->assertStringContainsString('Despesa', $msg);
        $this->assertStringContainsString('Receita', $msg);
    }

    public function test_field_change_message_for_date(): void
    {
        $msg = $this->formatter->fieldChangeMessage('date', '2026-06-15', '2026-06-20');

        $this->assertStringContainsString('📅', $msg);
        $this->assertStringContainsString('Data', $msg);
        $this->assertStringContainsString('alterada', $msg); // feminino
        $this->assertStringContainsString('15/06/2026', $msg);
        $this->assertStringContainsString('20/06/2026', $msg);
    }

    public function test_field_change_message_for_description(): void
    {
        $msg = $this->formatter->fieldChangeMessage('description', 'Almoço', 'Jantar');

        $this->assertStringContainsString('💸', $msg);
        $this->assertStringContainsString('Descrição', $msg);
        $this->assertStringContainsString('alterada', $msg); // feminino
        $this->assertStringContainsString('Almoço', $msg);
        $this->assertStringContainsString('Jantar', $msg);
    }

    public function test_field_change_message_for_category(): void
    {
        $msg = $this->formatter->fieldChangeMessage('category', 'Alimentação', 'Lazer');

        $this->assertStringContainsString('🏷', $msg);
        $this->assertStringContainsString('Categoria', $msg);
        $this->assertStringContainsString('alterada', $msg); // feminino
        $this->assertStringContainsString('Alimentação', $msg);
        $this->assertStringContainsString('Lazer', $msg);
    }

    public function test_field_change_message_for_observations(): void
    {
        $msg = $this->formatter->fieldChangeMessage('observations', 'Obs antiga', 'Obs nova');

        $this->assertStringContainsString('📝', $msg);
        $this->assertStringContainsString('Observações', $msg);
        $this->assertStringContainsString('alteradas', $msg); // feminino plural
        $this->assertStringContainsString('Obs antiga', $msg);
        $this->assertStringContainsString('Obs nova', $msg);
    }

    public function test_field_change_message_null_values_display_dash(): void
    {
        // Valor antigo null → "—", novo valor normal.
        $msg = $this->formatter->fieldChangeMessage('category', null, 'Nova Cat');

        $this->assertStringContainsString('—', $msg);
        $this->assertStringContainsString('Nova Cat', $msg);
    }

    public function test_field_change_message_escapes_html_in_values(): void
    {
        $msg = $this->formatter->fieldChangeMessage('description', '<b>old</b>', '<i>new</i>');

        $this->assertStringNotContainsString('<b>', $msg);
        $this->assertStringNotContainsString('<i>', $msg);
        $this->assertStringContainsString('&lt;b&gt;', $msg);
        $this->assertStringContainsString('&lt;i&gt;', $msg);
    }

    public function test_field_change_message_escapes_malicious_date_in_old_value(): void
    {
        // WARNING #1 (reviewer): data maliciosa injetada via sessão legacy/corrompida
        // deve ser escapada — nunca exibida sem escape no Telegram HTML.
        $msg = $this->formatter->fieldChangeMessage('date', '<script>alert(1)</script>', '2026-06-20');

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringContainsString('&lt;script&gt;', $msg);
    }

    public function test_field_change_message_escapes_malicious_type_in_old_value(): void
    {
        // WARNING #1 (reviewer): tipo malicioso injetado via sessão legacy/corrompida
        // deve ser escapado — nunca exibido sem escape no Telegram HTML.
        $msg = $this->formatter->fieldChangeMessage('type', '<script>xss</script>', 'expense');

        $this->assertStringNotContainsString('<script>', $msg);
        $this->assertStringContainsString('&lt;script&gt;', $msg);
    }

    public function test_field_change_message_gender_agreement_pt_br(): void
    {
        // Concordância de gênero PT-BR:
        // amount/type → "alterado" (masculino)
        // date/description/category → "alterada" (feminino)
        // observations → "alteradas" (feminino plural)

        $this->assertStringContainsString(
            'alterado',
            $this->formatter->fieldChangeMessage('amount', 10.0, 20.0),
        );
        $this->assertStringContainsString(
            'alterado',
            $this->formatter->fieldChangeMessage('type', 'expense', 'income'),
        );
        $this->assertStringContainsString(
            'alterada',
            $this->formatter->fieldChangeMessage('date', '2026-01-01', '2026-01-02'),
        );
        $this->assertStringContainsString(
            'alterada',
            $this->formatter->fieldChangeMessage('description', 'A', 'B'),
        );
        $this->assertStringContainsString(
            'alterada',
            $this->formatter->fieldChangeMessage('category', 'A', 'B'),
        );
        $this->assertStringContainsString(
            'alteradas',
            $this->formatter->fieldChangeMessage('observations', 'A', 'B'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | M3 — Labels no resumo de confirmação (T3.7 / D3=B)
    |--------------------------------------------------------------------------
    */

    public function test_summary_includes_labels_when_present(): void
    {
        $dto = new TransactionData(
            description: 'Almoço no restaurante',
            amount: 47.50,
            type: 'expense',
            category: 'Alimentação',
            date: '2026-06-15',
            labels: ['Almoço', 'Restaurante'],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🏷️ <b>Labels:</b>', $out);
        $this->assertStringContainsString('#Almoço', $out);
        $this->assertStringContainsString('#Restaurante', $out);
        // Labels devem aparecer entre Categoria e Data.
        $this->assertStringContainsString("🏷 <b>Categoria:</b> Alimentação\n🏷️ <b>Labels:</b>", $out);
        $this->assertStringContainsString("\n📅 <b>Data:</b> 15/06/2026", $out);
    }

    public function test_summary_omits_labels_when_empty(): void
    {
        $dto = new TransactionData(
            description: 'Uber',
            amount: 23.90,
            type: 'expense',
            category: 'Transporte',
            date: '2026-06-15',
            labels: [],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('Labels:', $out);
    }

    public function test_summary_formats_labels_with_hash_prefix(): void
    {
        // Labels formatadas com prefixo #, mesmo que já tenham # no input.
        $dto = new TransactionData(
            description: 'Compras',
            amount: 100.0,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            labels: ['Mercado', '#feira'],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('#Mercado', $out);
        $this->assertStringContainsString('#feira', $out, 'Hash duplicado deve ser evitado');
    }

    public function test_summary_escapes_html_in_labels(): void
    {
        // Label com & (ex.: "C&A" — loja brasileira real) deve ser escapada
        // como #C&amp;A, não #C&A. O & não-escapado quebra o parse_mode=HTML.
        $dto = new TransactionData(
            description: 'Compra na C&A',
            amount: 199.90,
            type: 'expense',
            category: 'Vestuário',
            date: '2026-06-15',
            labels: ['C&A', '<script>'],
        );

        $out = $this->formatter->summary($dto);

        // A label "C&A" vira #C&amp;A (escapada), não #C&A.
        $this->assertStringNotContainsString('#C&A', $out, '& não escapado quebraria parse_mode=HTML');
        $this->assertStringContainsString('#C&amp;A', $out, '& deve ser escapado como &amp;');

        // Label com <script> também deve ser escapada.
        $this->assertStringNotContainsString('#<script>', $out);
        $this->assertStringContainsString('#&lt;script&gt;', $out);
    }

    public function test_list_summary_escapes_html_in_labels(): void
    {
        // Mesma proteção de HTML-escaping deve valer para listSummary.
        $transactions = $this->makeTransactions([
            [
                'description' => 'Compra na C&A',
                'amount' => 199.90,
                'type' => 'expense',
                'category' => 'Vestuário',
                'date' => '2026-06-15',
                'labels' => ['C&A'],
            ],
        ]);

        $out = $this->formatter->listSummary($transactions, 1);

        $this->assertStringNotContainsString('#C&A', $out);
        $this->assertStringContainsString('#C&amp;A', $out);
    }

    /*
    |--------------------------------------------------------------------------
    | M-ITENS-4 — Testes de items no summary() e fieldChangeMessage
    |--------------------------------------------------------------------------
    */

    public function test_summary_with_empty_items_omits_items_block(): void
    {
        // CT-150 / AC-029: items=[] → bloco "🛒 Itens:" NÃO aparece.
        $dto = new TransactionData(
            description: 'Aluguel',
            amount: 1500.00,
            type: 'expense',
            category: 'Moradia',
            date: '2026-06-15',
            items: [],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('🛒 <b>Itens:</b>', $out);
    }

    public function test_summary_with_single_complete_item(): void
    {
        // CT-151 / AC-053: 1 item completo → formato PT-BR com R$.
        $dto = new TransactionData(
            description: 'Mercado',
            amount: 65.80,
            type: 'expense',
            category: 'Alimentação',
            date: '2026-06-15',
            items: [
                ['name' => 'Arroz', 'qty' => 2.0, 'unitPrice' => 32.90, 'subtotal' => 65.80],
            ],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🛒 <b>Itens:</b>', $out);
        $this->assertStringContainsString('1. Arroz (x2 — R$ 32,90 = R$ 65,80)', $out);
    }

    public function test_summary_with_name_only_item(): void
    {
        // Item só-nome → sem parênteses.
        $dto = new TransactionData(
            description: 'Mercado',
            amount: 10.00,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            items: [
                ['name' => 'Detergente', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🛒 <b>Itens:</b>', $out);
        $this->assertStringContainsString('1. Detergente', $out);
        // Não deve ter parênteses de qty/preço.
        $this->assertStringNotContainsString('Detergente (', $out);
    }

    public function test_summary_with_decimal_qty_uses_comma(): void
    {
        // CT-152 / AC-054: qty decimal → vírgula PT-BR.
        $dto = new TransactionData(
            description: 'Queijo',
            amount: 15.00,
            type: 'expense',
            category: 'Alimentação',
            date: '2026-06-15',
            items: [
                ['name' => 'Queijo', 'qty' => 1.5, 'unitPrice' => 10.00, 'subtotal' => 15.00],
            ],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('1. Queijo (x1,5 — R$ 10,00 = R$ 15,00)', $out);
    }

    public function test_summary_with_exactly_10_items_shows_all_without_more_message(): void
    {
        // CT-134 / AC-027: 10 itens → todos exibidos, sem "... e mais".
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['name' => "Item {$i}", 'qty' => 1.0, 'unitPrice' => (float) $i, 'subtotal' => (float) $i];
        }

        $dto = new TransactionData(
            description: 'Compra grande',
            amount: 55.00,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            items: $items,
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🛒 <b>Itens:</b>', $out);
        // Todos os 10 numerados.
        $this->assertStringContainsString('1.', $out);
        $this->assertStringContainsString('10.', $out);
        // Sem mensagem de "e mais".
        $this->assertStringNotContainsString('e mais', $out);
    }

    public function test_summary_with_11_items_shows_10_plus_singular_more(): void
    {
        // CT-135 / AC-028: 11 itens → 10 + "... e mais 1 item." (singular).
        $items = [];
        for ($i = 1; $i <= 11; $i++) {
            $items[] = ['name' => "Item {$i}", 'qty' => 1.0, 'unitPrice' => (float) $i, 'subtotal' => (float) $i];
        }

        $dto = new TransactionData(
            description: 'Compra grande',
            amount: 66.00,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            items: $items,
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🛒 <b>Itens:</b>', $out);
        $this->assertStringContainsString('<i>... e mais 1 item.</i>', $out);
        // Item 11 não aparece individualmente.
        $this->assertStringNotContainsString('11.', $out);
    }

    public function test_summary_with_12_items_shows_10_plus_plural_more(): void
    {
        // CT-136 / AC-026: 12 itens → 10 + "... e mais 2 itens." (plural).
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['name' => "Item {$i}", 'qty' => 1.0, 'unitPrice' => (float) $i, 'subtotal' => (float) $i];
        }

        $dto = new TransactionData(
            description: 'Compra grande',
            amount: 78.00,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            items: $items,
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringContainsString('🛒 <b>Itens:</b>', $out);
        $this->assertStringContainsString('<i>... e mais 2 itens.</i>', $out);
    }

    public function test_summary_escapes_html_in_item_name(): void
    {
        // CT-149 / AC-045: name com HTML/JS → escapado no Telegram.
        $dto = new TransactionData(
            description: 'Teste XSS',
            amount: 10.00,
            type: 'expense',
            category: 'Outros',
            date: '2026-06-15',
            items: [
                ['name' => '<script>alert(1)</script>', 'qty' => 1.0, 'unitPrice' => 10.00, 'subtotal' => 10.00],
            ],
        );

        $out = $this->formatter->summary($dto);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function test_summary_items_sorted_same_as_sheets(): void
    {
        // CT-133 / AC-025: ordenação consistente com Sheets (subtotal ASC + fallback).
        $dto = new TransactionData(
            description: 'Mercado',
            amount: 90.00,
            type: 'expense',
            category: 'Alimentação',
            date: '2026-06-15',
            items: [
                ['name' => 'Item P', 'qty' => 1.0, 'unitPrice' => 20.00, 'subtotal' => 20.00],
                ['name' => 'Item Q', 'qty' => 1.0, 'unitPrice' => 5.00, 'subtotal' => 5.00],
                ['name' => 'Item R', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
            ],
        );

        $out = $this->formatter->summary($dto);

        // Subtotal ASC: Q (5.00) antes de P (20.00), sem-subtotal R ao final.
        $this->assertStringContainsString('1. Item Q (x1 — R$ 5,00 = R$ 5,00)', $out);
        $this->assertStringContainsString('2. Item P (x1 — R$ 20,00 = R$ 20,00)', $out);
        $this->assertStringContainsString('3. Item R', $out);
    }

    public function test_format_field_value_items_returns_count(): void
    {
        // CT-158: formatFieldValue('items', [...]) → "3 itens".
        $items = [
            ['name' => 'A', 'qty' => 1.0, 'unitPrice' => 10.00, 'subtotal' => 10.00],
            ['name' => 'B', 'qty' => 2.0, 'unitPrice' => 5.00, 'subtotal' => 10.00],
            ['name' => 'C', 'qty' => null, 'unitPrice' => null, 'subtotal' => null],
        ];

        // Testamos via fieldChangeMessage que usa formatFieldValue internamente.
        $msg = $this->formatter->fieldChangeMessage('items', [], $items);

        $this->assertStringContainsString('sem itens', $msg);
        $this->assertStringContainsString('3 itens', $msg);
    }

    public function test_format_field_value_empty_items_returns_sem_itens(): void
    {
        $msg = $this->formatter->fieldChangeMessage('items', ['some old'], []);

        $this->assertStringContainsString('sem itens', $msg);
    }

    public function test_field_change_verb_for_items_is_alterados(): void
    {
        // CT-158: verbo "alterados" para items.
        $msg = $this->formatter->fieldChangeMessage('items', [], [
            ['name' => 'A', 'qty' => 1.0, 'unitPrice' => 10.00, 'subtotal' => 10.00],
        ]);

        $this->assertStringContainsString('alterados', $msg);
        $this->assertStringContainsString('🛒', $msg);
        $this->assertStringContainsString('Itens', $msg);
        $this->assertStringContainsString('de', $msg);
        $this->assertStringContainsString('para', $msg);
    }
}
