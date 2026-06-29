<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO imutável representando uma transação extraída de texto livre (ou imagem).
 *
 * Espelha o JSON retornado pelo DeepSeek (spec §8.1) e os campos validados
 * (spec §9). Instâncias são construídas tipicamente via {@see fromArray()},
 * que aceita chaves em snake_case vindas do JSON do LLM.
 *
 * Semântica dos campos "não extraídos":
 *  - `amount = null`  → valor não identificado no texto (M5 pede o valor).
 *  - `type = null`    → tipo ambíguo (M5 pergunta despesa/receita).
 *  - `date = null`    → não deve ocorrer na prática (DateNormalizer devolve
 *                       "hoje" como default), mas aceito para robustez.
 */
final readonly class TransactionData
{
    /**
     * Limite máximo de caracteres da descrição (spec §9: máx 500).
     */
    public const int DESCRIPTION_MAX_LENGTH = 500;

    /**
     * Limite de segurança para armazenamento no banco de dados (defesa contra LLM
     * descontrolado ou usuário colando lista gigante). Acima disso, trunca
     * preservando os primeiros N itens.
     *
     * Decisão P6=a: sem limite de armazenamento no banco de dados. O teto aqui é
     * puramente sanitização — 200 itens é um valor pragmático que cobre
     * qualquer cupom fiscal real sem explodir o documento.
     */
    public const int ITEMS_MAX_STORED = 200;

    /**
     * Truncamento visual no Telegram (resumo de confirmação, edição).
     * Decisão P6=a: "~10 itens + '... e mais N itens'".
     */
    public const int ITEMS_MAX_DISPLAY = 10;

    /**
     * @param  array<int, string>                                                  $labels  Lista de etiquetas (default vazio).
     * @param  list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>  $items   Lista de itens descritivos (default vazio).
     */
    public function __construct(
        public ?string $description,
        public ?float $amount = null,
        public ?string $type = null,
        public ?string $category = null,
        public array $labels = [],
        public ?string $date = null,
        public ?string $observations = null,
        public ?float $confidence = null,
        public array $items = [],
    ) {}

    /**
     * Constrói o DTO a partir do array decodificado do JSON do DeepSeek.
     *
     * Aceita chaves em snake_case (formato nativo do LLM) e normaliza:
     *  - labels não-array → array vazio;
     *  - strings vazias → null (exceto description, validada no serviço);
     *  - description truncada com "..." quando excede 500 caracteres.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $labels = $data['labels'] ?? [];
        $labels = is_array($labels)
            ? array_values(array_filter(
                array_map(static fn ($v): string => trim((string) $v), $labels),
                static fn (string $v): bool => $v !== '',
            ))
            : [];

        return new self(
            description: self::normalizeDescription(self::stringOrNull($data['description'] ?? null)),
            amount: self::floatOrNull($data['amount'] ?? null),
            type: self::normalizeType(self::stringOrNull($data['type'] ?? null)),
            category: self::stringOrNull($data['category'] ?? null),
            labels: $labels,
            date: self::stringOrNull($data['date'] ?? null),
            observations: self::stringOrNull($data['observations'] ?? null),
            confidence: self::floatOrNull($data['confidence'] ?? null),
            items: self::normalizeItems($data['items'] ?? []),
        );
    }

    /**
     * Constroi uma nova instância com os mesmos valores de `$this`,
     * sobrescrevendo apenas os campos presentes em `$overrides`.
     *
     * Diferente de `??` na construção, usa `array_key_exists` para
     * distinguir "não informado" de "explicitamente null". Isso permite
     * que campos como `category` e `observations` recebam `null`.
     *
     * Usado por todos os with* (description, category, labels, items)
     * e por {@see withField()} para reduzir repetição.
     *
     * @param  array<string, mixed>  $overrides  Campos a sobrescrever.
     */
    private function clone(array $overrides): self
    {
        $get = fn (string $key, mixed $default): mixed =>
            array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new self(
            description: $get('description', $this->description),
            amount: $get('amount', $this->amount),
            type: $get('type', $this->type),
            category: $get('category', $this->category),
            labels: $get('labels', $this->labels),
            date: $get('date', $this->date),
            observations: $get('observations', $this->observations),
            confidence: $get('confidence', $this->confidence),
            items: $get('items', $this->items),
        );
    }

    /**
     * Retorna uma nova instância com a descrição substituída (e truncada
     * caso exceda o limite de 500 caracteres). Imutável.
     */
    public function withDescription(string $description): self
    {
        return $this->clone(['description' => self::normalizeDescription($description)]);
    }

    /**
     * Retorna uma nova instância com a categoria substituída (M8).
     *
     * O valor é normalizado via {@see stringOrNull()} — string vazia vira
     * `null` (equivalente a "sem categoria"). Imutável.
     */
    public function withCategory(?string $category): self
    {
        return $this->clone(['category' => self::stringOrNull($category)]);
    }

    /**
     * Retorna uma nova instância com a lista de labels substituída (M8).
     *
     * Normalização aplicada:
     *  - cada elemento é `trim()`-ado e convertido para string;
     *  - entradas vazias (após trim) são removidas;
     *  - reindexa com `array_values()` para manter a invariante `list<string>`.
     *
     * Passar `[]` (array vazio) zera os labels.
     *
     * @param  array<int, string>  $labels
     */
    public function withLabels(array $labels): self
    {
        $clean = array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), $labels),
            static fn (string $v): bool => $v !== '',
        ));

        return $this->clone(['labels' => $clean]);
    }

    /**
     * Retorna uma nova instância com a lista de items substituída.
     *
     * O array fornecido é normalizado via {@see normalizeItems()} para
     * garantir invariantes (name não-vazio, tipos corretos, truncamento).
     * Imutável.
     *
     * @param  array  $items  Lista bruta de items a normalizar.
     * @return self  Nova instância com items normalizados.
     */
    public function withItems(array $items): self
    {
        return $this->clone(['items' => self::normalizeItems($items)]);
    }

    /**
     * Indica se o DTO está completo o suficiente para persistência em
     * banco de dados (tabela `transactions` exige os quatro campos).
     *
     * Campos avaliados: `amount`, `type`, `description`, `date`. Os demais
     * (`category`, `labels`, `observations`) são opcionais no schema.
     *
     * Útil para o caller (ex.: `RegisterTransaction`) checar antes de
     * chamar `saveTransaction()` — se faltar algum campo, o fluxo
     * conversacional deve pedir o dado ao usuário (M5.2) em vez de
     * persistir um documento corrompido.
     */
    public function isComplete(): bool
    {
        return $this->amount !== null
            && $this->type !== null
            && $this->description !== null
            && $this->date !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Serialização para o draft da sessão (M7)
    |--------------------------------------------------------------------------
    */

    /**
     * Serializa o DTO como array snake_case para persistência no campo
     * `draft` da sessão no Redis (Hash `session:{chatId}`).
     *
     * Round-trip com {@see fromDraftArray()}: o que entra sai idêntico.
     * Campos null são omitidos para economizar espaço e deixar o draft
     * legível em inspeção (ex.: draft com só description+date sinaliza
     * visualmente que amount/type estão faltando).
     *
     * @return array<string, mixed>
     */
    public function toDraftArray(): array
    {
        return array_filter([
            'description' => $this->description,
            'amount' => $this->amount,
            'type' => $this->type,
            'category' => $this->category,
            'labels' => $this->labels,
            'date' => $this->date,
            'observations' => $this->observations,
            'confidence' => $this->confidence,
            'items' => $this->items,
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * Desserializa o DTO a partir do array guardado em `draft`.
     *
     * Aceita array vazio/null (devolve DTO totalmente null) — usado quando
     * a sessão foi recém-criada sem draft ainda. Reaproveita {@see fromArray()}
     * para compartilhar toda a normalização (type, labels, etc.).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromDraftArray(?array $data): self
    {
        return self::fromArray($data ?? []);
    }

    /**
     * Devolve uma nova instância com o campo `$field` substituído pelo valor
     * fornecido ( já validado/normalizado pelo caller).
     *
     * Usado pelo fluxo conversacional M7 quando o usuário responde um campo
     * pedível (AWAITING_DATA) ou edita um campo (AWAITING_EDITION). Aceita
     * apenas os campos realmente editáveis (amount/type/date/description/
     * category/observations); qualquer outro nome lança InvalidArgumentException
     * — bug de programação, nunca input de usuário (input é validado antes).
     */
    public function withField(string $field, mixed $value): self
    {
        return match ($field) {
            'amount' => $this->clone(['amount' => $value]),
            'type' => $this->clone(['type' => $value]),
            'date' => $this->clone(['date' => $value]),
            'description' => $this->withDescription((string) $value),
            'category' => $this->clone(['category' => $value]),
            'observations' => $this->clone(['observations' => $value]),
            'labels' => $this->withLabels(is_array($value) ? $value : []),
            'items' => $this->withItems(is_array($value) ? $value : []),
            default => throw new \InvalidArgumentException(
                "Campo não editável no DTO: {$field}.",
            ),
        };
    }

    /**
     * Devolve o valor bruto de um campo pelo nome (para captura do valor
     * antigo antes de withField no fluxo de edição).
     *
     * Universo idêntico a EDITABLE_FIELDS_MAP do ConversationRouter:
     * amount, type, date, description, category, observations, items.
     * Labels e confidence NÃO são acessíveis (não são editáveis pelo picker).
     *
     * @param  string  $field  "amount"|"type"|"date"|"description"|"category"|"observations"|"items".
     * @return mixed Valor bruto (float, string, ou null).
     */
    public function getFieldValue(string $field): mixed
    {
        return match ($field) {
            'amount' => $this->amount,
            'type' => $this->type,
            'date' => $this->date,
            'description' => $this->description,
            'category' => $this->category,
            'observations' => $this->observations,
            'items' => $this->items,
            default => throw new \InvalidArgumentException(
                "Campo não acessível no DTO: {$field}.",
            ),
        };
    }

    /**
     * Trunca um texto para o comprimento máximo, anexando "..." quando
     * excede. Usado tanto para description quanto para name de items.
     *
     * @param  string  $text       Texto não-nulo (caller já garantiu).
     * @param  int     $maxLength  Limite de caracteres (ex.: 500).
     * @return string  Texto truncado com "..." se excedeu o limite.
     */
    private static function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }

    /**
     * Trunca a descrição para o máximo de 500 caracteres, anexando "..."
     * nos 3 últimos quando o limite é excedido. Mantém o valor null.
     */
    private static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return self::truncateText($description, self::DESCRIPTION_MAX_LENGTH);
    }

    /**
     * Normaliza o tipo: aceita "expense"/"income" (case-insensitive) ou null.
     */
    private static function normalizeType(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = strtolower(trim($type));

        return in_array($normalized, ['expense', 'income'], true) ? $normalized : null;
    }

    /**
     * Coerce para string não-vazia ou null.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * Coerce para float ou null (preserva valores numéricos vindos do JSON).
     */
    private static function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Normaliza a lista de items vinda do JSON do LLM ou do ItemsParser.
     *
     * Para cada item:
     *  - `name`: trim, string não-vazia (item descartado se name vazio);
     *  - `qty`: coerce para float; null se ausente/não-numérico;
     *  - `unitPrice`: coerce para float; null se ausente/não-numérico;
     *  - `subtotal`: coerce para float; null se ausente/não-numérico.
     *
     * Não calcula subtotal (responsabilidade do ItemsParser quando há dados
     * suficientes; LLM pode enviar subtotal pré-calculado).
     *
     * Não clampar negativos (Decisão Portão 2: aceitar descontos de cupom).
     *
     * Trunca para ITEMS_MAX_STORED como defesa sanitária.
     *
     * @param  mixed  $items  Esperado list<array>; defensivo contra LLM.
     * @return list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>
     */
    private static function normalizeItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $rawName = $raw['name'] ?? null;
            // W1: coerce int/float name (ex.: código de barras) para string antes
            // de stringOrNull — sem isso, seria descartado silenciosamente.
            if (is_int($rawName) || is_float($rawName)) {
                $rawName = (string) $rawName;
            }
            $name = self::stringOrNull($rawName);
            if ($name === null) {
                // Item sem name é descartado (log warning via caller, não aqui).
                continue;
            }

            if (mb_strlen($name) > self::DESCRIPTION_MAX_LENGTH) {
                $name = self::truncateText($name, self::DESCRIPTION_MAX_LENGTH);
            }

            $qty = self::floatOrNull($raw['qty'] ?? null);
            // W3: qty negativo não tem significado — clampa para null.
            // unitPrice/subtotal negativos são OK (CT-106: descontos de cupom).
            if ($qty !== null && $qty < 0) {
                $qty = null;
            }

            $clean[] = [
                'name' => $name,
                'qty' => $qty,
                'unitPrice' => self::floatOrNull($raw['unitPrice'] ?? null),
                'subtotal' => self::floatOrNull($raw['subtotal'] ?? null),
            ];

            if (count($clean) >= self::ITEMS_MAX_STORED) {
                break;
            }
        }

        return array_values($clean);
    }
}
