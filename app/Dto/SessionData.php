<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO imutável representando a porção "mutável" da sessão conversacional
 * (`sessions/{chat_id}`) que o fluxo M7/M8/P7-A precisa persistir a cada
 * transição de estado.
 *
 * Substitui o set de 10 parâmetros nomeados do
 * {@see \App\Services\Google\FirestoreService::setSession()} (estado + draft +
 * flags de UI + ids de mensagens + clearFields) por um único valor tipado.
 * O id do chat continua sendo parâmetro do serviço porque é a chave do
 * documento, não parte do "conteúdo" da sessão.
 *
 * Mapeamento entre parâmetros do método antigo e propriedades deste DTO:
 *  - `$state`             → `state`
 *  - `$draft`             → `draft` (array snake_case vindo de {@see TransactionData::toDraftArray()})
 *  - `$awaitingField`     → `awaitingField`
 *  - `$messageIdConfirm`  → `messageIdConfirm`
 *  - `$messageIdEditPicker` → `messageIdEditPicker` (CT-047 / P7-A)
 *  - `$source`            → `source` ("text" | "image" | "wizard")
 *  - `$retryCount`        → `retryCount` (null = preservar; 0 = reset explícito)
 *  - `$pickerConsumed`    → `pickerConsumed` (P7-A: distingue 1º click de re-click em Y)
 *
 * A lista de campos a serem **apagados** (`clearFields` no método antigo)
 * permanece como segundo argumento de `FirestoreService::setSession()` —
 * não faz parte do DTO porque depende de contexto externo (quais campos
 * o caller considera stale), não da sessão em si.
 *
 * **Filtro de merge preservado (P7-A-2)**: as regras de omissão aplicadas
 * por {@see toMergeArray()} são idênticas às do antigo
 * `FirestoreService::filterSessionField()`:
 *  - `null` em qualquer campo → omitir (merge não sobrescreve valor existente);
 *  - `0` em `message_id_*` → omitir (sentinela "mensagem não enviada" — sem
 *    isto, helpers que devolvem 0 quando a mensagem é null poluem o doc);
 *  - demais valores (`false`, `0` em `retry_count`, strings, arrays) → preservar.
 *
 * Não impõe validação de `state` contra o enum {@see \App\Enums\ConversationState}
 * — o caller (Router/Handler) é responsável por passar valores válidos. Manter
 * o DTO agnóstico evita acoplamento com a máquina de estados e simplifica
 * testes (state pode ser uma string arbitrária em testes unitários do DTO).
 */
final readonly class SessionData
{
    /**
     * @param  array<string, mixed>|null  $draft  Draft serializado (snake_case).
     */
    public function __construct(
        public ?string $state = null,
        public ?array $draft = null,
        public ?string $awaitingField = null,
        public ?int $messageIdConfirm = null,
        public ?int $messageIdEditPicker = null,
        public ?string $source = null,
        public ?int $retryCount = null,
        public ?bool $pickerConsumed = null,
    ) {}

    /**
     * Serializa o DTO como array snake_case pronto para `mergeDocument`.
     *
     * Aplica o filtro herdado de `FirestoreService::filterSessionField()`
     * (P7-A-2): `null` sempre omitido; `0` em `message_id_*` omitido (sentinela
     * "mensagem não enviada" do `BotMessenger::messageId`); demais valores
     * preservados — incluindo `false` em `picker_consumed` e `0` em
     * `retry_count`, que são semânticos (reset explícito / "ainda não consumido").
     *
     * O carimbo `updated_at` é fornecido pelo caller (serviço) para que o DTO
     * permaneça livre de dependências de relógio/timezone e possa ser
     * instanciado em testes sem mockar `Carbon` ou similar.
     *
     * @return array<string, mixed>
     */
    public function toMergeArray(string $updatedAt): array
    {
        return array_filter([
            'state' => $this->state,
            'draft' => $this->draft,
            'awaiting_field' => $this->awaitingField,
            'message_id_confirm' => $this->messageIdConfirm,
            'message_id_edit_picker' => $this->messageIdEditPicker,
            'source' => $this->source,
            'retry_count' => $this->retryCount,
            'picker_consumed' => $this->pickerConsumed,
            'updated_at' => $updatedAt,
        ], self::filterSessionField(...), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Lista de campos a serem **removidos** do documento via
     * `FieldValue::delete()` (não cobertos pelo merge).
     *
     * Inicialmente vazia — o caller passa a lista real como segundo
     * argumento de `FirestoreService::setSession()`, pois depende de
     * contexto externo (quais campos o caller considera stale após a
     * transição). Este método existe na API do DTO para simetria com
     * {@see toMergeArray()} e para evolução futura (ex.: o DTO poder
     * sinalizar "se você estava em X, limpe Y").
     *
     * @return list<string>
     */
    public function clearFields(): array
    {
        return [];
    }

    /**
     * Filtro de campo de sessão para `array_filter` em {@see toMergeArray()}.
     *
     * P7-A-2 (LOW2): extraído de closure inline do antigo `FirestoreService::setSession()`
     * para método estático — facilita teste unitário e evita recriação da
     * closure a cada chamada.
     *
     * Regras:
     *  - `null` → omitir do merge (não mexe no campo existente);
     *  - `message_id_* === 0` → omitir (sentinela "mensagem não enviada");
     *  - demais valores (incluindo `false`, `0` em outros campos) → preservar.
     *
     * @param  array-key  $key
     */
    private static function filterSessionField(mixed $value, string $key): bool
    {
        if ($value === null) {
            return false;
        }

        // S3: message_id_* com valor 0 = "mensagem não enviada" — não
        // persiste (mantém doc limpo).
        if (str_starts_with($key, 'message_id_') && $value === 0) {
            return false;
        }

        return true;
    }
}
