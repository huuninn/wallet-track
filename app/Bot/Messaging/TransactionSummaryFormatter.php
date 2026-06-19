<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Dto\TransactionData;

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
     * Map type interno → rótulo em PT-BR (espelha {@see \App\Services\Google\SheetsService}).
     */
    private const array TYPE_LABELS = [
        'expense' => 'Despesa',
        'income' => 'Receita',
    ];

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
     * Compose o prompt natural para pedir um campo ao usuário (AWAITING_DATA).
     *
     * @param  string  $field  "amount"|"type"|"date".
     */
    public function askPrompt(string $field): string
    {
        $label = self::FIELD_LABELS[$field] ?? $field;

        return match ($field) {
            'amount' => "Não consegui identificar o 💵 <b>valor</b>. Qual o valor da transação? (ex.: <code>47,50</code>)",
            'type' => "Não consegui identificar o 🔖 <b>tipo</b>. É <b>despesa</b> ou <b>receita</b>?",
            'date' => "Não consegui identificar a 📅 <b>data</b>. Qual a data? (ex.: <code>15/06/2026</code> ou <code>ontem</code>)",
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
            'amount' => "✏️ Digite o novo 💵 <b>valor</b> (ex.: <code>50,00</code>):",
            'type' => "✏️ Digite o novo 🔖 <b>tipo</b> (<b>despesa</b> ou <b>receita</b>):",
            'date' => "✏️ Digite a nova 📅 <b>data</b> (ex.: <code>15/06/2026</code> ou <code>ontem</code>):",
            'description' => "✏️ Digite a nova 💸 <b>descrição</b>:",
            'category' => "✏️ Digite a nova 🏷 <b>categoria</b>:",
            'observations' => "✏️ Digite as novas observações:",
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
