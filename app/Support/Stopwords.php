<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stopwords PT-BR + extrator de keywords (M8).
 *
 * Implementa a primeira metade do algoritmo de sugestão de labels definido
 * em `docs/04-clarificacoes.md` §4: a partir da descrição de uma transação,
 * devolver uma lista ordenada de tokens "úteis" — palavras com significado
 * próprio, sem stopwords, deduplicadas, com tamanho mínimo.
 *
 * A lista `STOPWORDS_PT_BR` é a **lista canônica** do projeto. Foi copiada
 * verbatim da §4 das Clarificações e não deve divergir sem aprovação
 * (qualquer mudança impacta a heurística de sugestões — ver testes
 * `StopwordsTest::test_stopwords_list_matches_specification`).
 *
 * Por que stopwords removidas? Palavras funcionais ("de", "da", "no", "com")
 * não têm valor discriminante para rotular uma transação — apareceriam em
 * praticamente toda descrição e dominariam a lista de sugestões, sugindo
 * "#de", "#em" etc. Removê-las mantém a lista semanticamente útil.
 *
 * Por que dedup com ordem de primeira ocorrência? A ordem transmite
 * relevância temporal: o primeiro substantivo (ex.: "iFood") é
 * tipicamente o mais discriminante; palavras no final ("japonês", "hoje")
 * são qualificadores. Preservar a ordem de leitura humana é melhor do que
 * re-ordenar por frequência.
 */
final class Stopwords
{
    /**
     * Stopwords canônicas em PT-BR.
     *
     * Lista **exata** da §4 das Clarificações. Já normalizadas (sem acento,
     * minúsculas). Usada como lookup O(1) via `in_array(..., true)` em
     * {@see extractKeywords()}.
     *
     * @var list<string>
     */
    public const array STOPWORDS_PT_BR = [
        // Preposições simples
        'de', 'da', 'do', 'das', 'dos',
        'em', 'no', 'na', 'nos', 'nas',
        // Artigos e contrações
        'a', 'o', 'as', 'os', 'ao', 'à', 'às', 'aos',
        // Conjunções
        'e', 'ou', 'mas', 'que', 'se', 'não',
        // Preposições compostas
        'com', 'para', 'pra', 'por', 'pelo', 'pela', 'pelos', 'pelas',
        // Indefinidos
        'um', 'uma', 'uns', 'umas',
        // Verbos auxiliares
        'é', 'foi', 'são', 'está', 'estava',
    ];

    /**
     * Indica se uma palavra é stopword.
     *
     * Aceita entrada em qualquer capitalização/acentuação — {@see TextNormalizer::fold()}
     * é aplicado tanto no token recebido quanto na lista canônica, garantindo que
     * "não", "NÃO", "Nao" e "nao" sejam corretamente reconhecidas como stopword
     * (W-1 da revisão M8). A lista canônica `STOPWORDS_PT_BR` permanece na forma
     * da spec (com acentos), mas é comparada em forma folded.
     *
     * @param  string  $token  Palavra em qualquer forma (será dobrada internamente).
     */
    public static function isStopword(string $token): bool
    {
        return in_array(TextNormalizer::fold($token), self::foldedStopwords(), true);
    }

    /**
     * Stopwords canônicas em forma "folded" (sem acento, minúsculas).
     *
     * Cache lazy de {@see STOPWORDS_PT_BR} processado uma única vez por
     * processo. Permite que `isStopword()` compare contra a forma
     * comparável canônica (folded) sem reprocessar a lista a cada chamada.
     *
     * @return list<string>
     */
    private static function foldedStopwords(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = array_map(
                static fn (string $word): string => TextNormalizer::fold($word),
                self::STOPWORDS_PT_BR,
            );
        }

        return $cache;
    }

    /**
     * Extrai as keywords significativas de uma descrição em PT-BR.
     *
     * Pipeline (espelha §4 das Clarificações):
     *
     *  1. Normaliza via {@see TextNormalizer::fold()} (lowercase + sem acento + trim).
     *  2. Tokeniza por espaços/pontuação: o regex `[\s,.;:!?()\[\]{}\-—\/]+`
     *     é o mesmo da spec — cobre hífens, travessões, slashes, colchetes.
     *  3. Remove stopwords (lista canônica acima).
     *  4. Remove tokens com `mb_strlen < $minLength` (default 3): descarta
     *     "r$", "tv", "1x" (≤ 2 chars) que poluem a lista.
     *  5. Deduplica preservando a ordem de primeira ocorrência.
     *
     * Exemplos:
     *
     *  - `extractKeywords("Paguei R$ 47,50 no almoço de hoje no iFood")`
     *    → `["paguei", "almoco", "hoje", "ifood"]` (após dedup; "de" e "no" removidos; "R$" e "47,50" < 3).
     *  - `extractKeywords("")`                                       → `[]`
     *  - `extractKeywords("de da do")`                                → `[]`
     *
     * @param  string  $description  Texto livre (ex.: descrição do TransactionData).
     * @param  int  $minLength  Comprimento mínimo (em caracteres multibyte) para um token sobreviver.
     * @return list<string> Lista ordenada de keywords únicas e normalizadas.
     */
    public static function extractKeywords(string $description, int $minLength = 3): array
    {
        $normalized = TextNormalizer::fold($description);

        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split(
            '/[\s,.;:!?()\[\]{}\-—\/]+/u',
            $normalized,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if ($tokens === false) {
            // preg_split falhou (raro). Devolve vazio em vez de propagar —
            // o caller (SuggestLabels) trata lista vazia como "sem keywords",
            // o que é o mesmo que considerar "input inválido".
            return [];
        }

        $seen = [];
        $result = [];

        foreach ($tokens as $token) {
            if (self::isStopword($token)) {
                continue;
            }

            if (mb_strlen($token) < $minLength) {
                continue;
            }

            // Preserva ordem de primeira ocorrência, deduplicando.
            if (isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $result[] = $token;
        }

        return $result;
    }
}
