<?php

declare(strict_types=1);

namespace App\Services\Google;

use App\Dto\TransactionData;
use App\Support\ItemsSorter;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Facades\Log;

/**
 * Camada de espelhamento Firestore → Google Sheets (M6).
 *
 * Cada transação persistida no Firestore (M5) é espelhada como uma linha na
 * planilha (spec §4). Esta classe contém toda a lógica de mapeamento
 * {@see TransactionData} → linha de 9 colunas, e depende apenas de
 * {@see SheetsGateway} (interface) — nunca do SDK bruto — o que a torna
 * trivialmente testável com {@see InMemorySheetsGateway}.
 *
 * Colunas da aba principal (linha 1 = cabeçalho, linha 2+ = dados):
 *
 *   A Data | B Descrição | C Valor | D Tipo | E Categoria | F Labels |
 *   G ID Firestore | H Observações | I Itens
 *
 * **Formatação visual** (FORMAT de data/moeda, freeze linha 1) é M10 (polish).
 * Aqui apenas garantimos o cabeçalho textual via {@see ensureHeaders()}.
 */
final class SheetsService
{
    /**
     * Cabeçalho canônico da aba principal (linha 1).
     */
    private const array HEADERS = [
        'Data',
        'Descrição',
        'Valor',
        'Tipo',
        'Categoria',
        'Labels',
        'ID Firestore',
        'Observações',
        'Itens',
    ];

    /**
     * Map type interno → rótulo em PT-BR exibido na coluna D.
     */
    private const array TYPE_MAP = [
        'expense' => 'Despesa',
        'income' => 'Receita',
    ];

    /**
     * Cabeçalho da aba auxiliar de categorias (somente escrita).
     */
    private const array CATEGORY_HEADERS = ['Categoria', 'Tipo padrão'];

    public function __construct(
        private readonly SheetsGateway $gateway,
        private readonly ItemsSorter $itemsSorter,
        private readonly string $sheetName = 'Transações',
        private readonly string $categoriesSheetName = 'Categorias',
    ) {}

    /**
     * Garante que a linha 1 contém o cabeçalho canônico.
     *
     * Idempotente: só escreve quando a aba está sem cabeçalho (getHeaderRow
     * devolve null/vazio). Se já existe qualquer cabeçalho, não reescreve —
     * preserva eventual customização humana da planilha.
     */
    public function ensureHeaders(): void
    {
        $header = $this->gateway->getHeaderRow();

        if ($header === null || $header === []) {
            $this->gateway->writeHeaderRow(self::HEADERS);
        }
    }

    /**
     * Espelha uma transação como nova linha na aba principal.
     *
     * Chama {@see ensureHeaders()} primeiro (defensivo — a planilha pode estar
     * vazia no primeiro lançamento), monta a row de 9 colunas na ordem do
     * schema e faz append (INSERT_ROWS).
     *
     * **Guarda de completude**: o schema exige `Valor` e `Tipo` preenchidos
     * (colunas C e D). Como o DTO aceita amount/type nullable (M3/M4 permitem
     * fluxo conversacional pedindo esses dados), validar aqui evita gravar
     * uma linha "corrompida" — o caller deve garantir DTO completo antes.
     *
     * @param  string  $firestoreId  UUID do documento Firestore (coluna G).
     *
     * @throws \InvalidArgumentException Quando `$dto->amount` ou `$dto->type`
     *                                   são null (mesma regra de saveTransaction).
     */
    public function appendTransaction(TransactionData $dto, string $firestoreId): void
    {
        if ($dto->amount === null || $dto->type === null) {
            throw new \InvalidArgumentException(
                'TransactionData incompleto: amount e type são obrigatórios para '
                .'espelhar a transação no Sheets. O caller deve garantir DTO '
                .'completo antes de chamar appendTransaction().'
            );
        }

        $this->ensureHeaders();

        $this->gateway->appendRow($this->buildRow($dto, $firestoreId));
    }

    /**
     * Sincroniza a aba auxiliar "Categorias" a partir do catálogo do Firestore.
     *
     * Escreve o cabeçalho `["Categoria","Tipo padrão"]` seguido de uma linha
     * por categoria (nome + tipo padrão). **Best-effort**: se a aba ainda não
     * foi criada (ou qualquer erro de I/O), captura, loga warning e **não
     * lança** — a aba pode não existir ainda; a aba principal de transações é
     * o produto principal e não deve bloquear por causa da auxiliar.
     *
     * O `default_type` é gravado cru ("expense"/"income"): a aba é auxiliar/
     * interna (consumo futuro por automações), sem necessidade do rótulo PT-BR.
     *
     * @param  list<array{display_name?: string, default_type?: string}>  $categories
     */
    public function syncCategories(array $categories): void
    {
        $rows = [self::CATEGORY_HEADERS];

        foreach ($categories as $category) {
            $rows[] = [
                (string) ($category['display_name'] ?? ''),
                (string) ($category['default_type'] ?? ''),
            ];
        }

        $range = $this->categoriesSheetName.'!A1:B'.count($rows);

        try {
            $this->gateway->writeAll($range, $rows);
        } catch (GoogleServiceException|\RuntimeException $e) {
            // Best-effort: aba auxiliar pode não existir (404 →
            // GoogleServiceException) ou rede pode falhar (RuntimeException).
            // Bug de programação NÃO é capturado aqui — propaga ao caller.
            Log::warning(
                'Falha best-effort ao sincronizar aba de Categorias no Sheets.',
                ['range' => $range, 'error' => $e->getMessage()],
            );
        }
    }

    /**
     * Monta a linha de 9 colunas na ordem exata do schema, aplicando todas
     * as conversões de formato (data ISO preservada, labels→vírgula, maps de
     * tipo, nulls→string vazia).
     *
     * @return list<mixed>
     */
    private function buildRow(TransactionData $dto, string $firestoreId): array
    {
        return [
            $this->formatDate($dto->date),          // A — Data (ISO)
            (string) ($dto->description ?? ''),      // B — Descrição
            $this->formatAmount($dto->amount),       // C — Valor (número)
            $this->mapType($dto->type),              // D — Tipo
            (string) ($dto->category ?? ''),         // E — Categoria
            $this->formatLabels($dto->labels),       // F — Labels
            $firestoreId,                            // G — ID Firestore
            (string) ($dto->observations ?? ''),     // H — Observações
            $this->formatItems($dto->items),         // I — Itens (NOVO)
        ];
    }

    /**
     * Devolve a data ISO `YYYY-MM-DD` validada (round-trip), pronta para
     * enviar ao Sheets via `USER_ENTERED`.
     *
     * A spec §4 diz explicitamente: "Data: ISO `YYYY-MM-DD` via API; Sheets
     * formata para `DD/MM/AAAA`". Enviar ISO é BOTH spec-compliant AND
     * universal: o Sheets interpreta `YYYY-MM-DD` corretamente em qualquer
     * locale (em locale en-US, um `DD/MM/AAAA` bruto seria mal-interpretado
     * — mês "15" inválido vira texto). Por isso **não convertemos** antes
     * do envio; a formatação visual `DD/MM/AAAA` é responsabilidade do
     * FORMAT de célula (polish M10).
     *
     * Valida o formato via round-trip (parse + re-format) para rejeitar
     * datas inválidas como "2026-13-45" e "2026-02-30", além de formatos
     * não-canônicos como "2026-1-9" (sem zero à esquerda). Em caso de
     * null/vazio/inválido devolve string vazia — a coluna Data fica em
     * branco em vez de corromper a linha (mantém para revisão humana).
     * Formato inválido também dispara `Log::warning`.
     */
    private function formatDate(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($date === false || $date->format('Y-m-d') !== $iso) {
            Log::warning('Data com formato inválido ao montar linha Sheets.', ['date' => $iso]);

            return '';
        }

        return $iso; // ISO — Sheets formata conforme locale (spec §4)
    }

    /**
     * Normaliza o valor para número de até 2 casas decimais.
     *
     * Como `amount` já é validado não-null pelo caller
     * ({@see appendTransaction()} guarda `amount !== null`), a assinatura
     * é `float` (não `?float`). Aqui apenas arredonda para 2 casas (spec §4
     * pede "2 casas"). O gateway real envia como `USER_ENTERED`, então o
     * Sheets interpreta como número.
     */
    private function formatAmount(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * Monta a string de labels no formato `tag1, tag2` (vírgula + espaço).
     *
     * Filtra vazias. Lista vazia → string vazia (coluna Labels em branco).
     *
     * @param  array<int, string>  $labels
     */
    private function formatLabels(array $labels): string
    {
        $tags = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            // Previne injeção de fórmula no Google Sheets (CWE-1236):
            // labels começando com =, +, -, @ são interpretadas como fórmulas.
            if (preg_match('/^[=+\-@]/', $label)) {
                $label = "'".$label;
            }
            $tags[] = $label;
        }

        return implode(', ', $tags);
    }

    /**
     * Map type interno → rótulo PT-BR (coluna D).
     *
     * Valor desconhecido é mantido cru com log warning (defensivo — o DTO já
     * normaliza type para "expense"/"income" ou null, e null foi barrado em
     * appendTransaction).
     */
    private function mapType(?string $type): string
    {
        if ($type !== null && isset(self::TYPE_MAP[$type])) {
            return self::TYPE_MAP[$type];
        }

        if ($type !== null) {
            Log::warning('Tipo desconhecido ao montar linha Sheets.', ['type' => $type]);
        }

        return (string) ($type ?? '');
    }

    /**
     * Monta a string da coluna I: items numerados, separados por quebra de
     * linha, ordenados por subtotal crescente (D-P4=c + D-PC1=a). Items sem
     * subtotal ficam ao final na ordem de entrada (fallback).
     *
     * Formato por linha (D-P5=a):
     *   1. Feijão (x2 — R$ 8,50 = R$ 17,00)
     *   2. Arroz 5kg (x1 — R$ 32,90 = R$ 32,90)
     *   3. Detergente
     *
     * Lista vazia → string vazia (coluna I em branco).
     *
     * Defesa CWE-1236: cada nome é escapado contra injeção de fórmula
     * (igual formatLabels) — prefixa "'" se começa com =, +, -, @.
     *
     * @param  list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>  $items
     */
    private function formatItems(array $items): string
    {
        if ($items === []) {
            return '';
        }

        $ordered = $this->itemsSorter->sort($items);

        $lines = [];
        $i = 1;
        foreach ($ordered as $item) {
            $lines[] = $i.'. '.$this->formatItemLine($item);
            $i++;
        }

        return implode("\n", $lines);
    }

    /**
     * Formata UMA linha de item para exibição na coluna I do Sheets.
     *
     * Variantes (spec §8.3):
     *   1. qty + unitPrice + subtotal todos não-null:
     *      "{name} (x{qty} — R$ {unit} = R$ {sub})"
     *   2. unitPrice + subtotal (sem qty):
     *      "{name} (R$ {unit} = R$ {sub})"
     *   3. Só subtotal (sem qty/unitPrice):
     *      "{name} (R$ {sub})"
     *   4. Só name: "{name}"
     *
     * qty inteiro (ex.: 2.0) → "2"; decimal (ex.: 1.5) → "1,5" (vírgula).
     * unitPrice e subtotal → number_format(2, ',', '.').
     * Travessão é U+2014 (em dash), NÃO hífen.
     *
     * Escapa CWE-1236 no name (prefixa "'" se começa com =+-@).
     */
    private function formatItemLine(array $item): string
    {
        $name = $this->escapeFormula($item['name']);

        $qty = $item['qty'];
        $unit = $item['unitPrice'];
        $sub = $item['subtotal'];

        // Variante 1: qty + unitPrice + subtotal.
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

        // Variante 2: unitPrice + subtotal (sem qty).
        if ($unit !== null && $sub !== null) {
            return sprintf(
                '%s (R$ %s = R$ %s)',
                $name,
                number_format($unit, 2, ',', '.'),
                number_format($sub, 2, ',', '.'),
            );
        }

        // Variante 3: só subtotal.
        if ($sub !== null) {
            return sprintf('%s (R$ %s)', $name, number_format($sub, 2, ',', '.'));
        }

        // Variante 4: só name.
        return $name;
    }

    /**
     * Previne injeção de fórmula no Google Sheets (CWE-1236).
     *
     * Idêntico à lógica de {@see formatLabels()}, aplicado isoladamente por
     * item (não na string inteira). Cada item começa em nova linha na célula,
     * e o Sheets pode interpretar cada `\n` como início de conteúdo —
     * escapar por item garante cobertura total mesmo se houver `\n` dentro
     * de um name (R5/CT-163).
     *
     * Prefixa com "'" (apóstrofo) quando o valor começa com =, +, -, @.
     */
    private function escapeFormula(string $value): string
    {
        if (preg_match('/^[=+\-@]/', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
