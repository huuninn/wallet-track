<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Dto\TransactionData;
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
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    private function makeTransactions(array $dataList): array
    {
        $out = [];
        foreach ($dataList as $i => $data) {
            $out[] = ['id' => "tx-{$i}", 'data' => $data];
        }

        return $out;
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
        $out = $this->formatter->listSummary([], 0);

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
        // Observações devem aparecer após a Data (última linha fixa).
        $this->assertStringContainsString("📅 <b>Data:</b> 15/06/2026\n📝 <b>Observações:</b>", $out);
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
}
