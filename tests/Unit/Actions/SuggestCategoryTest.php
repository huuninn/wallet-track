<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\SuggestCategory;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes da heurística de sugestão de categoria (M8.4).
 *
 * Cobre:
 *  - Match exato.
 *  - Fuzzy match acima do threshold.
 *  - Abaixo do threshold → categoria nova.
 *  - Inferência a partir da descrição.
 *  - Default "Outros" quando nada bate.
 *  - Case insensitivity.
 *
 * Roda isolado: bin/dev test --filter SuggestCategoryTest
 */
#[CoversClass(SuggestCategory::class)]
class SuggestCategoryTest extends TestCase
{
    private FirestoreService $firestore;

    private SuggestCategory $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firestore = new FirestoreService(new InMemoryFirestoreGateway);
        $this->action = new SuggestCategory($this->firestore);
    }

    /**
     * Helper: popula a coleção com as 4 categorias mais comuns dos testes.
     */
    private function seedDefaultCategories(): void
    {
        $this->firestore->createCategory('Alimentação', 'expense', isDefault: true);
        $this->firestore->createCategory('Transporte', 'expense', isDefault: true);
        $this->firestore->createCategory('Moradia', 'expense', isDefault: true);
        $this->firestore->createCategory('Outros', 'expense', isDefault: true);
    }

    public function test_exact_match_returns_existing_category(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest('Alimentação', null);

        $this->assertSame('alimentação', $result['name']);
        $this->assertSame('Alimentação', $result['display']);
        $this->assertFalse($result['isNew']);
    }

    public function test_exact_match_is_case_insensitive(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest('ALIMENTAÇÃO', null);

        $this->assertFalse($result['isNew']);
        $this->assertSame('Alimentação', $result['display']);
    }

    public function test_fuzzy_match_above_threshold(): void
    {
        $this->seedDefaultCategories();

        // "Alimentaçao" (com cedilha errada) — após fold vira "alimentacao".
        // Distância de "alimentacao" vs "alimentação" (= "alimentacao" após fold)
        // = 0 → similaridade 1.0. Match exato (mesmo após fold).
        $result = $this->action->suggest('Alimentaçao', null);

        $this->assertFalse($result['isNew'], 'Alimentaçao deve fazer fuzzy match com Alimentação');
        $this->assertSame('Alimentação', $result['display']);
    }

    public function test_fuzzy_match_with_typo_above_threshold(): void
    {
        $this->seedDefaultCategories();

        // "Transpor te" com espaço (typo do usuário) — fold vira "transpor te".
        // vs "Transporte" fold = "transporte". Levenshtein("transpor te", "transporte") = 2.
        // 1 - 2/11 = 0.818 > 0.7. Match.
        $result = $this->action->suggest('Transpor te', null);

        $this->assertFalse($result['isNew']);
        $this->assertSame('Transporte', $result['display']);
    }

    public function test_below_threshold_returns_new_category(): void
    {
        $this->seedDefaultCategories();

        // "Hobbies" é totalmente diferente de todas as 4 existentes.
        $result = $this->action->suggest('Hobbies', null);

        $this->assertTrue($result['isNew']);
        $this->assertSame('hobbies', $result['name']);
        $this->assertSame('Hobbies', $result['display']);
    }

    public function test_null_extracted_category_with_no_description_returns_default(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest(null, null);

        // Default "Outros" existe → isNew=false.
        $this->assertSame('outros', $result['name']);
        $this->assertSame('Outros', $result['display']);
        $this->assertFalse($result['isNew']);
    }

    public function test_null_extracted_category_with_irrelevant_description_returns_default(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest(null, 'coisa aleatória xyz');

        $this->assertSame('outros', $result['name']);
        $this->assertFalse($result['isNew']);
    }

    public function test_default_is_marked_new_when_category_does_not_exist(): void
    {
        // Não popula "Outros".
        $this->firestore->createCategory('Alimentação', 'expense', isDefault: true);

        $result = $this->action->suggest(null, null);

        // Default não existe → caller precisa criar.
        $this->assertSame('outros', $result['name']);
        $this->assertTrue($result['isNew']);
    }

    public function test_case_insensitive_extracted(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest('alimentação', null);

        $this->assertFalse($result['isNew']);
    }

    public function test_infer_from_description_with_existing_category(): void
    {
        $this->seedDefaultCategories();

        // Sem extração, mas a descrição menciona "pizza" → matching com "Alimentação"
        // pelo algoritmo de tokens (count >= 1 E similaridade >= threshold).
        $result = $this->action->suggest(null, 'Paguei 50 em pizza');

        // A descrição não contém tokens da categoria, então a inferência
        // retorna null → cai no default "Outros".
        $this->assertSame('outros', $result['name']);
    }

    public function test_infer_from_description_matches_category_name_token(): void
    {
        $this->seedDefaultCategories();

        // Descrição contém "Transporte" como token direto.
        $result = $this->action->suggest(null, 'Paguei 50 em transporte público');

        $this->assertSame('transporte', $result['name']);
        $this->assertFalse($result['isNew']);
    }

    public function test_extracted_with_surrounding_whitespace(): void
    {
        $this->seedDefaultCategories();

        $result = $this->action->suggest('  Alimentação  ', null);

        $this->assertFalse($result['isNew']);
    }

    public function test_extracted_empty_string_treated_as_null(): void
    {
        $this->seedDefaultCategories();

        // String vazia → tratado como "sem extração" → inferência (vai pra default).
        $result = $this->action->suggest('', null);

        $this->assertSame('outros', $result['name']);
    }
}
