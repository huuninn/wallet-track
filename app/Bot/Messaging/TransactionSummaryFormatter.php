<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Dto\TransactionData;
use App\Services\Google\SheetsService;
use App\Support\CategoryEmojiMap;
use App\Support\ItemsSorter;

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
    public function __construct(
        private readonly ItemsSorter $sorter = new ItemsSorter,
    ) {}
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
     * Fallback visual para categorias fora do mapa canônico (ver
     * {@see CategoryEmojiMap}). Usado na listagem compacta do `/ultimos`
     * para sinalizar "categoria personalizada".
     */
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

    private const array FIELD_EMOJIS = [
        'amount' => '💵',
        'type' => '🔖',
        'date' => '📅',
        'description' => '💸',
        'category' => '🏷',
        'observations' => '📝',
        'items' => '🛒',
    ];

    private const array FIELD_LABELS_DISPLAY = [
        'amount' => 'Valor',
        'type' => 'Tipo',
        'date' => 'Data',
        'description' => 'Descrição',
        'category' => 'Categoria',
        'observations' => 'Observações',
        'items' => 'Itens',
    ];

    private const array FIELD_CHANGE_VERB = [
        'amount' => 'alterado',
        'type' => 'alterado',
        'date' => 'alterada',
        'description' => 'alterada',
        'category' => 'alterada',
        'observations' => 'alteradas',
        'items' => 'alterados',
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
        $E = self::FIELD_EMOJIS;
        $L = self::FIELD_LABELS_DISPLAY;

        $lines = [
            '📝 <b>Confirme a transação</b>',
            '',
            "{$E['description']} <b>{$L['description']}:</b> ".$this->escape($dto->description),
            "{$E['amount']} <b>{$L['amount']}:</b> ".$this->formatAmount($dto->amount),
            "{$E['type']} <b>{$L['type']}:</b> ".$this->formatType($dto->type),
        ];

        $category = $dto->category ?? '—';
        $lines[] = "{$E['category']} <b>{$L['category']}:</b> ".$this->escape($category);

        // Labels (D3=B): exibe como "#Almoço #Restaurante" se houver.
        $labelsStr = $this->formatLabels($dto->labels);
        if ($labelsStr !== '') {
            $lines[] = "🏷️ <b>Labels:</b> {$labelsStr}";
        }

        if ($dto->observations !== null && $dto->observations !== '') {
            $lines[] = "{$E['observations']} <b>{$L['observations']}:</b> ".$this->escape($dto->observations);
        }

        // Bloco de items (após observações, antes da data).
        $itemsBlock = $this->formatItemsBlock($dto->items);
        if ($itemsBlock !== '') {
            $lines[] = '';
            $lines[] = $itemsBlock;
        }

        $lines[] = "{$E['date']} <b>{$L['date']}:</b> ".$this->formatDate($dto->date);

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
            'observations' => '✏️ Digite as novas 📝 <b>observações</b>:',
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

    /**
     * Formata a mensagem de feedback de alteração de campo.
     *
     * Formato: "<Emoji> <Campo> alteradX de <valor antigo> para <valor novo>"
     * com concordância de gênero PT-BR.
     *
     * @param  string  $field  Nome canônico ("amount"|"type"|...).
     * @param  mixed  $oldRaw  Valor antigo (do DTO antes do withField).
     * @param  mixed  $newRaw  Valor novo (normalizado pelo validador).
     * @return string Mensagem HTML (parse_mode=HTML).
     */
    public function fieldChangeMessage(string $field, mixed $oldRaw, mixed $newRaw): string
    {
        $emoji = self::FIELD_EMOJIS[$field] ?? '📝';
        $label = self::FIELD_LABELS_DISPLAY[$field] ?? ucfirst($field);
        $verb = self::FIELD_CHANGE_VERB[$field] ?? 'alterado';

        $oldDisplay = $this->formatFieldValue($field, $oldRaw);
        $newDisplay = $this->formatFieldValue($field, $newRaw);

        return "{$emoji} {$label} {$verb} de {$oldDisplay} para {$newDisplay}";
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
        return CategoryEmojiMap::EMOJIS[$category] ?? self::CATEGORY_EMOJI_FALLBACK;
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
            $clean[] = '#'.htmlspecialchars(ltrim($label, '#'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

        // Fallback: escapa valor não-confiável — nunca exibir texto cru
        // sem escape em mensagens Telegram HTML.
        return self::TYPE_LABELS[$type] ?? htmlspecialchars($type, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        // Se não for ISO válido, escapa o valor cru — nunca exibir texto
        // não-confiável sem escape em mensagens Telegram HTML.
        return $date !== false
            ? $date->format('d/m/Y')
            : htmlspecialchars($iso, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Formata um valor bruto de campo para exibição na mensagem de alteração.
     *
     * @param  string  $field  Nome canônico do campo.
     * @param  mixed  $value  Valor bruto (float, string, ou null).
     */
    private function formatFieldValue(string $field, mixed $value): string
    {
        return match ($field) {
            'amount' => $this->formatAmount($value !== null && is_numeric($value) ? (float) $value : null),
            'type' => $this->formatType(is_string($value) ? $value : null),
            'date' => $this->formatDate(is_string($value) ? $value : null),
            'items' => $this->formatItemsCount($value),
            default => $this->escape(is_string($value) && $value !== '' ? $value : null),
        };
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

    /**
     * Monta o bloco HTML de items para o resumo do Telegram.
     *
     * Trunca em ITEMS_MAX_DISPLAY (10), exibindo "... e mais N itens" para o resto.
     * Ordenação por subtotal crescente (mesma regra do Sheets — D-P4=c).
     *
     * Escapa HTML em todos os nomes (defesa contra XSS/injeção no Telegram).
     *
     * @param  list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>  $items
     */
    private function formatItemsBlock(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $ordered = $this->sorter->sort($items);
        $display = array_slice($ordered, 0, TransactionData::ITEMS_MAX_DISPLAY);
        $remaining = count($ordered) - count($display);

        $lines = ['🛒 <b>Itens:</b>'];
        $i = 1;
        foreach ($display as $item) {
            $lines[] = $i.'. '.$this->formatItemLineHtml($item);
            $i++;
        }

        if ($remaining > 0) {
            $noun = $remaining === 1 ? 'item' : 'itens';
            $lines[] = "<i>... e mais {$remaining} {$noun}.</i>";
        }

        return implode("\n", $lines);
    }

    /**
     * Formata UMA linha de item para o Telegram (HTML).
     *
     * 4 variantes (mesma lógica do SheetsService::formatItemLine):
     *   1. qty + unitPrice + subtotal: "Nome (x2 — R$ 32,90 = R$ 65,80)"
     *   2. unitPrice + subtotal (sem qty): "Nome (R$ 32,90 = R$ 65,80)"
     *   3. Só subtotal: "Nome (R$ 65,80)"
     *   4. Só name: "Nome"
     *
     * Diferente do SheetsService: aplica htmlspecialchars no name (escape XSS
     * para Telegram parse_mode=HTML), e NÃO aplica escapeFormula (fórmulas não
     * são interpretadas pelo Telegram).
     *
     * qty inteiro → sem decimais; qty decimal → vírgula PT-BR.
     * unit/sub → number_format com vírgula decimal e ponto de milhar.
     * Travessão — (U+2014).
     *
     * @param  array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}  $item
     */
    private function formatItemLineHtml(array $item): string
    {
        $name = htmlspecialchars($item['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $qty = $item['qty'];
        $unit = $item['unitPrice'];
        $sub = $item['subtotal'];

        if ($qty !== null && $unit !== null && $sub !== null) {
            $qtyStr = ($qty == (int) $qty)
                ? (string) (int) $qty
                : rtrim(rtrim(number_format($qty, 2, ',', ''), '0'), ',');

            return sprintf(
                '%s (x%s — R$ %s = R$ %s)',
                $name,
                $qtyStr,
                number_format($unit, 2, ',', '.'),
                number_format($sub, 2, ',', '.'),
            );
        }

        if ($unit !== null && $sub !== null) {
            return sprintf(
                '%s (R$ %s = R$ %s)',
                $name,
                number_format($unit, 2, ',', '.'),
                number_format($sub, 2, ',', '.'),
            );
        }

        if ($sub !== null) {
            return sprintf('%s (R$ %s)', $name, number_format($sub, 2, ',', '.'));
        }

        return $name;
    }

    /**
     * Formata a contagem de items para mensagem de feedback de alteração.
     *
     * Ex.: 3 itens, 1 item, sem itens.
     */
    private function formatItemsCount(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'sem itens';
        }
        $n = count($value);

        return $n.' '.($n === 1 ? 'item' : 'itens');
    }
}
