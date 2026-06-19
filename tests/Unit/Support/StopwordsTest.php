<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Stopwords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes das stopwords PT-BR e do extrator de keywords (M8).
 *
 * Cobertura:
 *  - Lista canônica das stopwords corresponde exatamente à §4 das Clarificações.
 *  - {@see Stopwords::isStopword()} responde corretamente.
 *  - {@see Stopwords::extractKeywords()} aplica todo o pipeline (fold,
 *    tokenização, remoção de stopwords, min-length, dedup, ordem).
 *
 * Roda isolado: bin/dev test --filter StopwordsTest
 */
#[CoversClass(Stopwords::class)]
class StopwordsTest extends TestCase
{
    public function test_stopwords_list_matches_specification(): void
    {
        // Lista canônica da §4 das Clarificações. Qualquer divergência aqui
        // é quebra de contrato com a spec — não toque sem aprovação.
        $expected = [
            'de', 'da', 'do', 'das', 'dos',
            'em', 'no', 'na', 'nos', 'nas',
            'a', 'o', 'as', 'os', 'ao', 'à', 'às', 'aos',
            'e', 'ou', 'mas', 'que', 'se', 'não',
            'com', 'para', 'pra', 'por', 'pelo', 'pela', 'pelos', 'pelas',
            'um', 'uma', 'uns', 'umas',
            'é', 'foi', 'são', 'está', 'estava',
        ];

        $this->assertSame($expected, Stopwords::STOPWORDS_PT_BR);
    }

    public function test_is_stopword_true_for_known_words(): void
    {
        // As stopwords são armazenadas já normalizadas (sem acento, minúsculas).
        // A lista inclui o caractere "à" (a com acento) — após fold(),
        // "à" vira "a" e ambas as representações casam.
        $this->assertTrue(Stopwords::isStopword('de'));
        $this->assertTrue(Stopwords::isStopword('da'));
        $this->assertTrue(Stopwords::isStopword('do'));
        $this->assertTrue(Stopwords::isStopword('e'));
        $this->assertTrue(Stopwords::isStopword('ou'));
        $this->assertTrue(Stopwords::isStopword('mas'));
        $this->assertTrue(Stopwords::isStopword('com'));
        $this->assertTrue(Stopwords::isStopword('para'));
        $this->assertTrue(Stopwords::isStopword('é'));
        $this->assertTrue(Stopwords::isStopword('foi'));
    }

    public function test_is_stopword_false_for_content_words(): void
    {
        $this->assertFalse(Stopwords::isStopword('almoco'));
        $this->assertFalse(Stopwords::isStopword('restaurante'));
        $this->assertFalse(Stopwords::isStopword('ifood'));
        $this->assertFalse(Stopwords::isStopword('conta'));
        $this->assertFalse(Stopwords::isStopword('luz'));
        $this->assertFalse(Stopwords::isStopword('energia'));
    }

    public function test_is_stopword_folds_input_internally(): void
    {
        // W-1: isStopword() agora aplica TextNormalizer::fold() internamente,
        // tanto no input quanto na lista canônica. Assim "DE" / "de" e "á" / "a"
        // são reconhecidos como a MESMA stopword.
        $this->assertTrue(Stopwords::isStopword('DE'), 'isStopword deve dobrar internamente (W-1)');
        $this->assertTrue(Stopwords::isStopword('á'), 'isStopword deve dobrar internamente (W-1)');
        $this->assertTrue(Stopwords::isStopword('NÃO'), 'isStopword deve dobrar internamente (W-1)');
    }

    public function test_extract_keywords_basic(): void
    {
        $keywords = Stopwords::extractKeywords('Paguei 50 reais no almoço de hoje');

        $this->assertSame(['paguei', 'reais', 'almoco', 'hoje'], $keywords);
    }

    public function test_extract_keywords_removes_stopwords(): void
    {
        // "de", "no", "da", "em" devem ser filtradas.
        $keywords = Stopwords::extractKeywords('Gastei com o Uber de manhã da empresa');

        $this->assertNotContains('com', $keywords);
        $this->assertNotContains('o', $keywords);
        $this->assertNotContains('de', $keywords);
        $this->assertNotContains('da', $keywords);
    }

    public function test_extract_keywords_min_length_3(): void
    {
        // Tokens de 1-2 chars são descartados.
        // NOTA: tokens com vírgula/separador são SPLITADOS pela tokenização
        // (a vírgula é delimitador), por isso "5,50" vira "5" e "50" — ambos
        // < 3 chars e removidos pelo filtro de min_length.
        $keywords = Stopwords::extractKeywords('R$ 5,50 a b cd xyz abcd');
        $this->assertContains('xyz', $keywords);
        $this->assertContains('abcd', $keywords);
        $this->assertNotContains('a', $keywords);
        $this->assertNotContains('b', $keywords);
        $this->assertNotContains('cd', $keywords);
    }

    public function test_extract_keywords_dedupes_preserving_order(): void
    {
        // "pizza" aparece 2x → só uma entrada; ordem preservada pela 1ª.
        $keywords = Stopwords::extractKeywords('Comi pizza no iFood e pizza hoje');

        $pizzaCount = count(array_filter($keywords, static fn (string $k): bool => $k === 'pizza'));
        $this->assertSame(1, $pizzaCount, 'pizza deve aparecer 1x (deduplicado)');

        // pizza antes de ifood (ordem de primeira ocorrência).
        $pizzaIdx = array_search('pizza', $keywords, true);
        $ifoodIdx = array_search('ifood', $keywords, true);
        $this->assertIsInt($pizzaIdx);
        $this->assertIsInt($ifoodIdx);
        $this->assertLessThan($ifoodIdx, $pizzaIdx);
    }

    public function test_extract_keywords_handles_punctuation(): void
    {
        $keywords = Stopwords::extractKeywords('Paguei R$ 47,50; gastei: muito dinheiro, foi caro!');

        // Pontuação removida pelo regex (vírgula, ponto-e-vírgula, dois-pontos, exclamação).
        $this->assertContains('paguei', $keywords);
        $this->assertContains('gastei', $keywords);
        $this->assertContains('dinheiro', $keywords);
        $this->assertContains('caro', $keywords);
    }

    public function test_extract_keywords_handles_slashes_and_dashes(): void
    {
        $keywords = Stopwords::extractKeywords('Uber uber-x vale-transporte almoço');

        $this->assertContains('uber', $keywords);
        $this->assertContains('vale', $keywords);
        $this->assertContains('transporte', $keywords);
        $this->assertContains('almoco', $keywords);
    }

    public function test_extract_keywords_lowercases_and_folds(): void
    {
        $keywords = Stopwords::extractKeywords('Açaí & Café - São Paulo');

        $this->assertContains('acai', $keywords);
        $this->assertContains('cafe', $keywords);
        // 'São' é stopword (verbo "ser") — após o fix W-1, é corretamente removido.
        $this->assertNotContains('sao', $keywords);
        $this->assertContains('paulo', $keywords);
    }

    public function test_extract_keywords_empty_for_only_stopwords(): void
    {
        $this->assertSame([], Stopwords::extractKeywords('de da do com para em no'));
    }

    public function test_extract_keywords_handles_empty_string(): void
    {
        $this->assertSame([], Stopwords::extractKeywords(''));
    }

    public function test_extract_keywords_handles_whitespace_only(): void
    {
        $this->assertSame([], Stopwords::extractKeywords('   '));
    }

    public function test_extract_keywords_handles_only_short_tokens(): void
    {
        $this->assertSame([], Stopwords::extractKeywords('a b c de da'));
    }

    public function test_extract_keywords_ct_020_scenario(): void
    {
        // Cenário do CT-020: "Paguei R$ 120,00 na conta de luz da enel"
        // → keywords: paga, conta, luz, enel (após remover "na", "de", "da").
        $keywords = Stopwords::extractKeywords('Paguei R$ 120,00 na conta de luz da enel');

        $this->assertContains('conta', $keywords);
        $this->assertContains('luz', $keywords);
        $this->assertContains('enel', $keywords);
        $this->assertNotContains('na', $keywords);
        $this->assertNotContains('de', $keywords);
        $this->assertNotContains('da', $keywords);
    }

    public function test_extract_keywords_filters_accented_stopwords(): void
    {
        // W-1: "não" e "com" são stopwords acentuadas. Após o fix, ambas
        // devem ser filtradas — "não" → "nao" (folded) deve ser removida,
        // e "com" (sem acento) também.
        $keywords = Stopwords::extractKeywords('não paguei com cartão');

        $this->assertNotContains('nao', $keywords);
        $this->assertContains('paguei', $keywords);
        $this->assertContains('cartao', $keywords); // 'cartão' vira 'cartao' (sem acento)
    }

    public function test_extract_keywords_filters_esta_sao_acentuados(): void
    {
        // W-1: "está", "foi", "são" são stopwords verbais acentuadas. Após
        // o fix, TODAS devem ser filtradas — resultado deve ser vazio.
        $keywords = Stopwords::extractKeywords('está foi são');

        $this->assertSame([], $keywords);
    }
}
