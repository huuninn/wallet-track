<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Dto\TransactionData;
use App\Services\Google\SheetsService;

/**
 * Formatação humanizada do resumo de um {@see TransactionData} para exibição
 * ao usuário (PT-BR).
 *
 * Centralizado aqui para que o contrato seja consistente entre
 * {@see NutgramBotMessenger} (produção) e {@see InMemoryBotMessenger}
 * (testes) — ambas as implementações usam o mesmo formatador para que a
 * string do resumo seja idêntica, e os testes podem assercionar sobre a
 * presença de substrings (ex.: "Valor: R$ 47,50") sem depender de detalhes
 * do transport (HTML vs Markdown).
 *
 * Separação pura de responsabilidades: nenhum I/O aqui, só string → string.
 */
final class TransactionSummaryFormatter
{
    /**
     * Map type interno → rótulo em PT-BR (espelha {@see SheetsService}).
     */
    private const array TYPE_LABELS = [
        'expense' => 'Despesa',
        'income' => 'Receita',
    ];

    /**
     * Emoji por tipo de transação (usado na linha 2 de {@see listRow()}).
     */
    private const array TYPE_EMOJIS = [
        'expense' => '💸',
        'income' => '💰',
    ];

    /**
     * Mapa categoria → emoji (linha 1 de {@see listRow()} e fallback `🏷`).
     *
     * Tabela alinhada com a spec §6.3 (9 categorias padrão + fallback genérico).
     * Categorias personalizadas usam o fallback se não houver match exato
     * (case-sensitive para preservar o nome original).
     */
    private const array CATEGORY_EMOJIS = [
        'Alimentação' => '🍕',
        'Transporte' => '🚗',
        'Moradia' => '🏠',
        'Saúde' => '❤️',
        'Educação' => '📚',
        'Lazer' => '🎮',
        'Salário' => '💰',
        'Freelance' => '💻',
        'Outros' => '📦',
    ];

    private const string CATEGORY_EMOJI_FALLBACK = '🏷';

    /**
     * Mapa de campos pedíveis → rótulos humanizados para prompts.
     *
     * Usado por {@see fieldLabel()} e indiretamente pelo Router para compor
     * perguntas ("Qual o valor da transação?").
     */
    private const array FIELD_LABELS = [
        'amount' => 'valor',
        'type' => 'tipo (despesa/receita)',
        'date' => 'data',
        'description' => 'descrição',
        'category' => 'categoria',
        'observations' => 'observações',
    ];

    /**
     * Monta o resumo multilinha do draft, com labels em PT-BR.
     *
     * Campos null aparecem como "—" para sinalizar visualmente ao usuário
     * que faltam dados (no fluxo de edição, o usuário pode querer revisar).
     *
     * @return string Texto multilinha em PT-BR (texto puro, sem HTML).
     */
    public function summary(TransactionData $dto): string
    {
        $lines = [
            '📝 <b>Confirme a transação</b>',
            '',
            '💸 <b>Descrição:</b> '.$this->escape($dto->description),
            '💵 <b>Valor:</b> '.$this->formatAmount($dto->amount),
            '🔖 <b>Tipo:</b> '.$this->formatType($dto->type),
        ];

        $category = $dto->category ?? '—';
        $lines[] = '🏷 <b>Categoria:</b> '.$this->escape($category);
        $lines[] = '📅 <b>Data:</b> '.$this->formatDate($dto->date);

        return implode("\n", $lines);
    }

    /**
     * Formata uma lista de transações para exibição em formato compacto (M9.5 / T-005).
     *
     * Layout de saída (HTML, parse_mode=HTML no Telegram):
     * ```
     * 📋 <b>Últimas {shown} transações</b>
     *
     * 1. 🍕 <b>Almoço restaurante</b>
     *    💸 Despesa · R$ 47,50 · Alimentação
     *    📅 15/06/2026 · #almoço #restaurante
     *
     * 2. 💰 <b>Salário Junho</b>
     *    📈 Receita · R$ 5.000,00 · Salário
     *    📅 01/06/2026
     *
     * <i>Mostrando {shown}.</i>
     * ```
     *
     * Cobertura de testes: CT-027, CT-027a, CT-027b, CT-027c, CT-028g.
     *
     * @param  list<array{id: string, data: array<string, mixed>}>  $transactions
     */
    public function listSummary(array $transactions, int $shown): string
    {
        $noun = $shown === 1 ? 'transação' : 'transações';
        $header = "📋 <b>Últimas {$shown} {$noun}</b>";

        if ($transactions === []) {
            return $header;
        }

        $rows = [];
        foreach ($transactions as $i => $doc) {
            $rows[] = $this->listRow($doc['data'] ?? [], $i + 1);
        }

        $footer = $shown === 1
            ? '<i>Mostrando 1 transação.</i>'
            : "<i>Mostrando {$shown} transações.</i>";

        return $header."\n\n".implode("\n\n", $rows)."\n\n".$footer;
    }

    /**
     * Compose o prompt natural para pedir um campo ao usuário (AWAITING_DATA).
     *
     * @param  string  $field  "amount"|"type"|"date".
     */
    public function askPrompt(string $field): string
    {
        $label = self::FIELD_LABELS[$field] ?? $field;

        return match ($field) {
            'amount' => 'Não consegui identificar o 💵 <b>valor</b>. Qual o valor da transação? (ex.: <code>47,50</code>)',
            'type' => 'Não consegui identificar o 🔖 <b>tipo</b>. É <b>despesa</b> ou <b>receita</b>?',
            'date' => 'Não consegui identificar a 📅 <b>data</b>. Qual a data? (ex.: <code>15/06/2026</code> ou <code>ontem</code>)',
            default => "Por favor, informe o campo <b>{$label}</b>:",
        };
    }

    /**
     * Compose o prompt para editar um campo (AWAITING_EDITION).
     */
    public function editPrompt(string $field): string
    {
        $label = self::FIELD_LABELS[$field] ?? $field;

        return match ($field) {
            'amount' => '✏️ Digite o novo 💵 <b>valor</b> (ex.: <code>50,00</code>):',
            'type' => '✏️ Digite o novo 🔖 <b>tipo</b> (<b>despesa</b> ou <b>receita</b>):',
            'date' => '✏️ Digite a nova 📅 <b>data</b> (ex.: <code>15/06/2026</code> ou <code>ontem</code>):',
            'description' => '✏️ Digite a nova 💸 <b>descrição</b>:',
            'category' => '✏️ Digite a nova 🏷 <b>categoria</b>:',
            'observations' => '✏️ Digite as novas observações:',
            default => "✏️ Digite o novo valor para <b>{$label}</b>:",
        };
    }

    /**
     * Devolve o rótulo humanizado de um campo (para logs/erros).
     */
    public function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de formato
    |--------------------------------------------------------------------------
    */

    /**
     * Formata uma única linha da listagem (formato compacto do spec §2.8 / T-005).
     *
     * @param  array<string, mixed>  $data  Documento da transação (campos do schema `transactions/`).
     * @param  int  $index  Posição 1-indexada na listagem.
     */
    private function listRow(array $data, int $index): string
    {
        $description = $this->escape((string) ($data['description'] ?? '—'));
        $category = (string) ($data['category'] ?? '');
        $catEmoji = $category !== '' ? $this->categoryEmoji($category) : self::CATEGORY_EMOJI_FALLBACK;

        $type = (string) ($data['type'] ?? '');
        $typeEmoji = self::TYPE_EMOJIS[$type] ?? self::CATEGORY_EMOJI_FALLBACK;
        $typeLabel = self::TYPE_LABELS[$type] ?? $type;

        $amount = isset($data['amount']) && is_numeric($data['amount'])
            ? (float) $data['amount']
            : null;
        $amountStr = $this->formatAmount($amount);

        $dateStr = $this->formatDate(isset($data['date']) ? (string) $data['date'] : null);

        $firstLine = "{$index}. {$catEmoji} <b>{$description}</b>";
        $secondLine = "   {$typeEmoji} {$typeLabel} · {$amountStr}".($category !== '' ? " · {$this->escape($category)}" : '');

        $labels = $this->formatLabels($data['labels'] ?? []);
        $thirdLine = $labels !== ''
            ? "   📅 {$dateStr} · {$labels}"
            : "   📅 {$dateStr}";

        return $firstLine."\n".$secondLine."\n".$thirdLine;
    }

    /**
     * Devolve o emoji de uma categoria, com fallback `🏷` para desconhecidas.
     */
    private function categoryEmoji(string $category): string
    {
        return self::CATEGORY_EMOJIS[$category] ?? self::CATEGORY_EMOJI_FALLBACK;
    }

    /**
     * Formata a lista de labels como `#label1 #label2`. Vazio → string vazia.
     *
     * @param  mixed  $labels  Aceita `list<string>` ou `null` (defensivo).
     */
    private function formatLabels(mixed $labels): string
    {
        if (! is_array($labels)) {
            return '';
        }

        $clean = [];
        foreach ($labels as $label) {
            $label = (string) $label;
            if ($label === '') {
                continue;
            }
            $clean[] = '#'.ltrim($label, '#');
        }

        return implode(' ', $clean);
    }

    private function formatAmount(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        // pt-BR: vírgula decimal, 2 casas. number_format não depende de intl.
        return 'R$ '.number_format($amount, 2, ',', '.');
    }

    private function formatType(?string $type): string
    {
        if ($type === null) {
            return '—';
        }

        return self::TYPE_LABELS[$type] ?? $type;
    }

    private function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        // Se não for ISO válido, devolve cru — o usuário saberá reconhecer.
        return $date !== false
            ? $date->format('d/m/Y')
            : $iso;
    }

    /**
     * Escapa caracteres HTML especiais para uso em mensagens Telegram HTML.
     */
    private function escape(?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
