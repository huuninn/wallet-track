<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\SuggestLabelsLLM;
use App\Dto\TransactionData;
use App\Services\DeepSeek\ChatCompleter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Feature\DeepSeek\FakeChatCompleter;
use Tests\TestCase;
use Throwable;

/**
 * Testes da action de sugestão de labels via LLM dedicado (M2).
 *
 * Cobre:
 *  - LLM devolve labels → capitalizadas via LabelFormatter.
 *  - Catálogo injetado no prompt.
 *  - Truncamento para max_labels.
 *  - Falha de LLM → [] sem exceção.
 *  - JSON inválido → [].
 *  - Marca preservada (P7).
 *  - Sem descrição nem categoria → [] sem chamar LLM.
 *  - Dedup case/accent insensitive.
 *
 * Usa um FakeSuggestionCompleter (stub local) que captura o systemPrompt
 * e devolve JSON de fixture, sem chamar a API real. O teste estende
 * {@see TestCase} para ter acesso ao container Laravel (config, etc.).
 *
 * Roda isoladamente: bin/dev test --filter SuggestLabelsLLMTest
 */
#[CoversClass(SuggestLabelsLLM::class)]
class SuggestLabelsLLMTest extends TestCase
{
    /**
     * Binda um FakeSuggestionCompleter e retorna a action + fake para asserções.
     *
     * @return array{0: SuggestLabelsLLM, 1: FakeSuggestionCompleter}
     */
    private function makeAction(string $response = '', ?Throwable $throw = null): array
    {
        $fake = new FakeSuggestionCompleter($response, $throw);
        $this->app->bind(ChatCompleter::class, fn () => $fake);

        return [$this->app->make(SuggestLabelsLLM::class), $fake];
    }

    private function draft(array $fields = []): TransactionData
    {
        return new TransactionData(
            description: $fields['description'] ?? null,
            amount: $fields['amount'] ?? null,
            type: $fields['type'] ?? null,
            category: $fields['category'] ?? null,
        );
    }

    public function test_llm_labels_are_capitalized(): void
    {
        [$action] = $this->makeAction(json_encode([
            'labels' => ['almoco', 'restaurante'],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['description' => 'Almoço no restaurante']),
        );

        // "almoco" → "Almoco" (P1), "restaurante" → "Restaurante" (P1).
        $this->assertSame(['Almoco', 'Restaurante'], $result);
    }

    public function test_catalog_is_injected_into_system_prompt(): void
    {
        [$action, $fake] = $this->makeAction(json_encode([
            'labels' => ['Almoço'],
        ], JSON_THROW_ON_ERROR));

        $action->suggest(
            $this->draft(['description' => 'Almoço']),
            labelCatalog: ['iFood', 'Restaurante'],
        );

        $this->assertNotNull($fake->systemPrompt);
        $this->assertStringContainsString('iFood, Restaurante', $fake->systemPrompt);
        // Placeholders devem ter sido substituídos.
        $this->assertStringNotContainsString('{{MAX_LABELS}}', $fake->systemPrompt);
        $this->assertStringNotContainsString('{{LABEL_CATALOG}}', $fake->systemPrompt);
    }

    public function test_truncates_to_max_labels(): void
    {
        [$action] = $this->makeAction(json_encode([
            'labels' => ['label1', 'label2', 'label3', 'label4', 'label5'],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['description' => 'Teste']),
        );

        // max_labels default = 3.
        $this->assertCount(3, $result);
    }

    public function test_llm_failure_returns_empty_array(): void
    {
        [$action] = $this->makeAction(throw: new \RuntimeException('API offline'));

        $result = $action->suggest(
            $this->draft(['description' => 'Teste']),
        );

        $this->assertSame([], $result);
    }

    public function test_invalid_json_returns_empty_array(): void
    {
        [$action] = $this->makeAction('isto não é JSON {{{');

        $result = $action->suggest(
            $this->draft(['description' => 'Teste']),
        );

        $this->assertSame([], $result);
    }

    public function test_empty_json_object_returns_empty_array(): void
    {
        [$action] = $this->makeAction('{}');

        $result = $action->suggest(
            $this->draft(['description' => 'Teste']),
        );

        $this->assertSame([], $result);
    }

    public function test_brand_preserved_via_label_formatter(): void
    {
        [$action] = $this->makeAction(json_encode([
            'labels' => ['iFood'],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['description' => 'Almoço no iFood']),
        );

        // "iFood" tem capitalização mista → P7 preserva.
        $this->assertSame(['iFood'], $result);
    }

    public function test_no_description_or_category_returns_empty_without_calling_llm(): void
    {
        // O Fake vai lançar se for chamado com throw set — mas como não
        // deve ser chamado, usamos response vazio (sem throw, só pra garantir).
        [$action, $fake] = $this->makeAction(json_encode(['labels' => []]));

        $result = $action->suggest(
            $this->draft(), // sem description nem category
        );

        $this->assertSame([], $result);
        // O systemPrompt NÃO deve ter sido capturado (LLM não foi chamado).
        $this->assertNull($fake->systemPrompt);
    }

    public function test_dedupes_case_and_accent_insensitive(): void
    {
        [$action] = $this->makeAction(json_encode([
            'labels' => ['almoco', 'Almoço', 'ALMOCO', 'Pizza'],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['description' => 'Almoço com pizza']),
        );

        // Após formatação: "Almoco", "Almoço", "Almoco", "Pizza"
        // TextNormalizer::fold("Almoco") === "almoco"
        // TextNormalizer::fold("Almoço") === "almoco" (idêntico após fold)
        // Então "Almoco" (primeiro) sobrevive, "Almoço" e "ALMOCO" são dedupados.
        // "Pizza" sobrevive.
        $this->assertCount(2, $result);
        $this->assertContains('Almoco', $result);
        $this->assertContains('Pizza', $result);
    }

    public function test_empty_labels_array_in_json_returns_empty(): void
    {
        [$action] = $this->makeAction(json_encode([
            'labels' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['description' => 'Teste']),
        );

        $this->assertSame([], $result);
    }

    public function test_only_category_without_description_still_calls_llm(): void
    {
        [$action, $fake] = $this->makeAction(json_encode([
            'labels' => ['Transporte'],
        ], JSON_THROW_ON_ERROR));

        $result = $action->suggest(
            $this->draft(['category' => 'Transporte']),
        );

        // description vazia, mas category preenchida → chama LLM.
        $this->assertNotNull($fake->systemPrompt);
        $this->assertSame(['Transporte'], $result);
    }

    public function test_strips_markdown_json_fences(): void
    {
        // O LLM pode devolver JSON cercado de ```json ... ```.
        // O parseAndNormalize deve tolerar isso.
        $json = json_encode(['labels' => ['almoco']], JSON_THROW_ON_ERROR);
        $withFences = "```json\n{$json}\n```";

        [$action] = $this->makeAction($withFences);

        $result = $action->suggest(
            $this->draft(['description' => 'Almoço']),
        );

        $this->assertSame(['Almoco'], $result);
    }

    public function test_strips_markdown_fences_without_language_hint(): void
    {
        // Fences sem "json" após ``` também devem ser tolerados.
        $json = json_encode(['labels' => ['pizza']], JSON_THROW_ON_ERROR);
        $withFences = "```\n{$json}\n```";

        [$action] = $this->makeAction($withFences);

        $result = $action->suggest(
            $this->draft(['description' => 'Pizza']),
        );

        $this->assertSame(['Pizza'], $result);
    }
}

/**
 * Stub de teste que implementa {@see ChatCompleter} para os testes de
 * {@see SuggestLabelsLLM}, sem tocar na rede.
 *
 * Similar ao {@see FakeChatCompleter}, mas
 * autônomo (não depende do namespace Feature).
 */
final class FakeSuggestionCompleter implements ChatCompleter
{
    /** Prompt do sistema recebido na última chamada (para asserções). */
    public ?string $systemPrompt = null;

    public function __construct(
        private readonly string $response = '',
        private readonly ?Throwable $throw = null,
    ) {}

    public function complete(string $systemPrompt, array $userMessages, array $options = []): string
    {
        $this->systemPrompt = $systemPrompt;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->response;
    }
}
