<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\Store\WalletStore;
use App\Support\Stopwords;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Heurística PHP (sem LLM) para sugestão de categoria — M8.
 *
 * Decide a categoria final de uma transação, a partir do que o extrator
 * (DeepSeek/Gemini) sugeriu e/ou do texto da descrição. Pode devolver:
 *
 *  - **Match exato** ("Alimentação" == "alimentação") → categoria existente.
 *  - **Fuzzy match acima do threshold** ("Alimentaçao" → "Alimentação") →
 *    categoria existente (corrige typo do LLM ou do usuário).
 *  - **Match abaixo do threshold** → devolve com `isNew: true`.
 *    O caller (ConversationRouter::enrichDtoWithSuggestions) aplica
 *    o display ao DTO. A criação efetiva da categoria só ocorre
 *    dentro de WalletStore::saveTransaction() via firstOrCreate,
 *    garantindo que a FK seja populada no momento da persistência.
 *  - **Sem categoria extraída** → inferência via keywords da descrição,
 *    ou fallback "Outros" como default canônico.
 *
 * O "cálculo de similaridade" usado aqui é a **distância de Levenshtein
 * normalizada** — a abordagem mais simples, robusta e suficiente para o
 * volume de uso (1 usuário, ~50-100 categorias no banco). Não usamos
 * embeddings nem similaridade semântica; o universo de categorias é
 * pequeno e finito.
 *
 * **UTF-8 com Levenshtein**: `levenshtein()` em PHP só funciona com
 * strings single-byte. Antes de chamar, fold() reduz os dois lados ao
 * mesmo alfabeto (sem acentos), mas os caracteres remanescentes ainda
 * podem ser multi-byte em tese. Em prática, com a remoção de acentos,
 * todas as letras latinas viram 1 byte e a função opera corretamente
 * (a operação em si não falha, mas o retorno é baseado em bytes, não em
 * caracteres visuais — o que é o que queremos para "diferença textual").
 */
final class SuggestCategory
{
    /**
     * Limiar mínimo de similaridade para aceitar fuzzy match.
     *
     * 0.7 = 70% de "igualdade textual" (Levenshtein 1 - normalizado). Valor
     * calibrado: aceita typos de 1-2 letras em palavras curtas ("Lazer" vs
     * "Lazre"), mas rejeita palavras realmente diferentes.
     */
    public const float FUZZY_THRESHOLD = 0.7;

    /**
     * Categoria default quando nenhuma inferência é possível.
     *
     * Espera-se que "Outros" esteja sempre presente no seed (vide §5 da
     * spec — M5.3 lista "Outros" entre as 9 categorias padrão). Este
     * método NÃO cria a categoria — se ela não existir em runtime (bug
     * de seed), o caller lidará com a persistência falha.
     */
    public const string DEFAULT_CATEGORY = 'Outros';

    public function __construct(
        private readonly WalletStore $store,
    ) {}

    /**
     * Sugere uma categoria para a transação.
     *
     * @param  string|null  $extractedCategory  Categoria sugerida pelo LLM (pode null).
     * @param  string|null  $description  Descrição (fonte para inferência quando $extractedCategory é null).
     * @return array{name: string, display: string, isNew: bool} Tupla: id canônico (name), rótulo legível (display), flag de nova categoria (isNew).
     */
    public function suggest(?string $extractedCategory, ?string $description): array
    {
        $categories = $this->store->getCategories();

        // 1) Categoria extraída presente → tenta match direto ou fuzzy.
        if ($extractedCategory !== null && trim($extractedCategory) !== '') {
            return $this->matchOrNew($extractedCategory, $categories);
        }

        // 2) Sem extração → tenta inferir pela descrição.
        if ($description !== null && trim($description) !== '') {
            $inferred = $this->inferFromDescription($description, $categories);
            if ($inferred !== null) {
                return $inferred;
            }
        }

        // 3) Nenhum sinal útil → default.
        return $this->buildCategoryResult(self::DEFAULT_CATEGORY, $categories, isNew: true);
    }

    /**
     * Tenta encontrar a categoria mais similar (exata → fuzzy). Se nada
     * bater acima do threshold, devolve como nova.
     *
     * @param  Collection<int, \App\Models\Category>  $categories
     * @return array{name: string, display: string, isNew: bool}
     */
    private function matchOrNew(string $extracted, Collection $categories): array
    {
        $extractedFold = TextNormalizer::fold($extracted);

        if ($extractedFold === '') {
            return $this->buildCategoryResult(self::DEFAULT_CATEGORY, $categories, isNew: true);
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($categories as $row) {
            $nameFold = TextNormalizer::fold($row->display_name);
            if ($nameFold === '') {
                continue;
            }

            $score = TextNormalizer::similarity($extractedFold, $nameFold);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        // Match perfeito (1.0) ou acima do threshold → aceita como existente.
        if ($best !== null && $bestScore >= self::FUZZY_THRESHOLD) {
            return $this->buildCategoryResult(
                $best->display_name,
                $categories,
                isNew: false,
            );
        }

        // Abaixo do threshold → categoria nova (o caller decide se cria).
        return $this->buildCategoryResult($extracted, $categories, isNew: true);
    }

    /**
     * Tenta inferir uma categoria a partir das keywords da descrição.
     *
     * Estratégia: para cada categoria existente, conta quantas keywords
     * (após fold) batem com tokens da categoria (após fold + tokenização).
     * A categoria com mais matches vence, SE tiver ao menos 1 match E a
     * MELHOR similaridade keyword↔token (não keyword-string-inteira) for
     * ≥ FUZZY_THRESHOLD — assim "transporte" sozinho bate exato com a
     * categoria "Transporte" mesmo se a descrição tem muitas outras palavras.
     *
     * A "similaridade por token" usa o melhor par keyword→token (e não a
     * string toda). Isso evita que uma keyword curta (1 match perfeito)
     * seja descartada porque a descrição tem 10 outras palavras.
     *
     * @param  Collection<int, \App\Models\Category>  $categories
     * @return array{name: string, display: string, isNew: bool}|null
     */
    private function inferFromDescription(string $description, Collection $categories): ?array
    {
        $keywords = Stopwords::extractKeywords($description);
        if ($keywords === []) {
            return null;
        }

        $bestCount = 0;
        $bestScore = 0.0;
        $best = null;

        foreach ($categories as $row) {
            $display = $row->display_name;
            $displayFold = TextNormalizer::fold($display);
            if ($displayFold === '') {
                continue;
            }

            $catTokens = preg_split(
                '/[\s,.;:!?()\[\]{}\-—\/]+/u',
                $displayFold,
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];

            if ($catTokens === []) {
                continue;
            }

            $catTokenSet = array_flip($catTokens);

            $matches = 0;
            $bestKeywordScore = 0.0;

            foreach ($keywords as $kw) {
                if (isset($catTokenSet[$kw])) {
                    $matches++;
                    // Match exato por token → similaridade 1.0.
                    $bestKeywordScore = max($bestKeywordScore, 1.0);
                } else {
                    // Considera fuzzy match: keyword próxima a algum token.
                    foreach ($catTokens as $token) {
                        $bestKeywordScore = max($bestKeywordScore, TextNormalizer::similarity($kw, $token));
                    }
                }
            }

            if ($matches === 0) {
                continue;
            }

            // Ranking: mais matches primeiro; desempate por melhor similaridade.
            if ($matches > $bestCount || ($matches === $bestCount && $bestKeywordScore > $bestScore)) {
                $bestCount = $matches;
                $bestScore = $bestKeywordScore;
                $best = $row;
            }
        }

        if ($best === null || $bestCount < 1 || $bestScore < self::FUZZY_THRESHOLD) {
            return null;
        }

        return $this->buildCategoryResult(
            $best->display_name,
            $categories,
            isNew: false,
        );
    }

    /**
     * Monta o array de resultado final.
     *
     * - `name` = id canônico (lowercase) — usado em lookups e como slug.
     * - `display` = rótulo legível (preserva capitalização do `display_name`
     *   quando a categoria existe; usa o input cru quando é nova).
     * - `isNew` = se é categoria nova (caller deve persistir).
     *
     * @param  Collection<int, \App\Models\Category>  $categories
     * @return array{name: string, display: string, isNew: bool}
     */
    private function buildCategoryResult(string $input, Collection $categories, bool $isNew): array
    {
        $display = trim($input);
        $name = mb_strtolower($display);

        // Se a categoria existe (input é o display_name de um doc), preserva
        // o `display_name` original do banco para não criar variantes
        // ortográficas ("Alimentação" vs "Alimentacao" no display).
        foreach ($categories as $row) {
            $rowName = $row->slug;
            if ($rowName === $name) {
                $display = $row->display_name;

                return [
                    'name' => $rowName,
                    'display' => $display,
                    'isNew' => false,
                ];
            }
        }

        return [
            'name' => $name,
            'display' => $display,
            'isNew' => $isNew,
        ];
    }
}
