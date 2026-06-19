<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

/**
 * Normalização textual canônica para uso em heurísticas (M8).
 *
 * Camada fina sobre {@see Normalizer::normalize()} (extensão `intl`) que
 * devolve uma string **comparável**: minúsculas + sem acentos + trim. Esta
 * forma é a entrada para todos os algoritmos que precisam comparar/agrupar
 * texto livre PT-BR: sugestão de labels (Stopwords::extractKeywords),
 * fuzzy match de categoria (SuggestCategory) e deduplicação case-insensitive
 * em geral.
 *
 * A invariante central: `TextNormalizer::fold($a) === TextNormalizer::fold($b)`
 * deve ser verdadeiro se e somente se `$a` e `$b` representam o mesmo
 * conteúdo (mesma palavra, mesmo radical, ignorando capitalização e
 * acentuação). É a "identidade" usada para deduplicar.
 *
 * Por que intl e não `iconv`/`mb_string`?
 *
 *  - `Normalizer::FORM_KD` (Compatibility Decomposition) é o padrão Unicode
 *    para descomparar acentos: "á" → "a" + combining acute (que é descartado),
 *    "ç" → "c" + cedilla, "ñ" → "n" + tilde. É a abordagem usada por
 *    Elasticsearch, Postgres `unaccent` e bibliotecas de busca de mercado.
 *  - `iconv('UTF-8', 'ASCII//TRANSLIT', ...)` funciona mas é dependente
 *    do locale do sistema operacional — "não-determinístico entre hosts".
 *  - `mb_convert_encoding` não remove acentos.
 *
 * Por isso a spec §4 (Clarificações) e a nota "temos intl real" no brief.
 */
final class TextNormalizer
{
    /**
     * Devolve a string "dobrada" (folded): minúsculas, sem acentos, sem
     * espaços nas pontas.
     *
     * Exemplos:
     *  - `"São Paulo"`     → `"sao paulo"`
     *  - `"Açaí & Café"`   → `"acai & cafe"`
     *  - `"  ALMOÇO  "`    → `"almoco"`
     *  - `""` ou `null`    → `""`
     *
     * Acentos são removidos via `Normalizer::FORM_KD` (que decompõe
     * caracteres acentuados em caractere base + combining mark, e a remoção
     * das combining marks no passo seguinte resulta em "limpo"). Acentos
     * cirílicos e outros scripts não-latinos também são reduzidos a uma
     * forma comparável no nível de compatibilidade Unicode.
     *
     * @param  string|null  $text  Texto a normalizar (null é tratado como vazio).
     */
    public static function fold(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Defesa em profundidade: a extensão intl está em composer.json
        // (ext-intl: *) e a imagem wallet-track:dev a inclui. Se um dia
        // algum runtime esquecê-la, falhamos rápido aqui em vez de gerar
        // strings "sujas" que silenciosamente quebram a heurística.
        if (! class_exists(Normalizer::class)) {
            throw new \RuntimeException(
                'Extensão PHP intl não está disponível. TextNormalizer requer Normalizer::normalize() '
                .'para descomparar acentos (formato Unicode KD).'
            );
        }

        // Passo 1: NFKD decompõe "á" → "a" + U+0301, "ç" → "c" + U+0327, etc.
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_KD);
        if ($decomposed === false) {
            // Texto não-normalizável (raro; ex.: sequências inválidas).
            // Devolve o input como fallback — melhor do que quebrar o fluxo.
            return mb_strtolower(trim($text));
        }

        // Passo 2: remove os combining marks (Mn = Nonspacing_Mark).
        // O regex com flag 'u' cobre todos os planos Unicode.
        $folded = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $decomposed;

        return mb_strtolower(trim($folded));
    }
}
