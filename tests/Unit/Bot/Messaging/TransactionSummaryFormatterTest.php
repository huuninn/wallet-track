<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Messaging;

use App\Bot\Messaging\TransactionSummaryFormatter;
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
}
