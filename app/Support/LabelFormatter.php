<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\SuggestLabelsLLM;
use App\Services\DeepSeek\DeepSeekService;
use App\Services\Gemini\GeminiService;

/**
 * Formatação canônica de labels para exibição e persistência.
 *
 * Aplica duas regras independentes sobre cada label individual:
 *
 *  **P1 — Capitalização Sentence Case (primeira letra da label inteira):**
 *  a label é "frase curta de busca", não "Nome Próprio". Portanto apenas a
 *  primeira letra da label inteira é capitalizada; o restante (incluindo
 *  palavras como "de", "da", "do", "e") permanece minúsculo. Acentos são
 *  preservados.
 *
 *  - `"almoco"`     → `"Almoco"`
 *  - `"café"`        → `"Café"`
 *  - `"casa da praia"` → `"Casa da praia"` (não "Casa Da Praia")
 *
 *  **P7 — Marcas e acrônimos preservados:**
 *  se uma palavra contém 2 ou mais letras maiúsculas (Unicode-aware),
 *  presumimos que se trata de uma marca, acrônimo ou nome próprio com
 *  capitalização intencional — e a preservamos intacta.
 *
 *  - `"iFood"` → `"iFood"` (tem "F" maiúscula no meio → marca)
 *  - `"PIX"`   → `"PIX"`   (3 maiúsculas → acrônimo)
 *  - `"iPhone"` → `"iPhone"` (tem "P" maiúscula → marca)
 *  - `"ifood"` → `"Ifood"` (sem maiúsculas → não é marca, aplica P1)
 *  - `"pix"`   → `"Pix"`   (sem maiúsculas → aplica P1)
 *
 * A operação é **idempotente**: {@see format()}({@see format()}($x)) === {@see format()}($x).
 * Labels que excedem {@see MAX_LENGTH} caracteres são truncadas com "...".
 *
 * Esta classe é consumida como defesa em profundidade pelos extratores
 * ({@see DeepSeekService}, {@see GeminiService})
 * e pela action de sugestão ({@see SuggestLabelsLLM}), garantindo
 * que toda label que chega ao banco de dados está no formato canônico,
 * independentemente do comportamento do LLM.
 *
 * @see TextNormalizer Para a operação complementar de dedup (fold).
 */
final class LabelFormatter
{
    /**
     * Comprimento máximo de uma label formatada (em caracteres Unicode).
     *
     * Labels acima deste limite são truncadas, com "..." anexado ao final.
     * 40 caracteres é generoso para PT-BR ("Manutenção preventiva do carro"
     * = 32 caracteres) e evita poluição visual nos botões inline do Telegram.
     */
    public const int MAX_LENGTH = 40;

    /**
     * Formata uma label aplicando as regras P1 (Sentence Case) e P7 (marcas/acrônimos).
     *
     * Algoritmo:
     *  1. `trim()` do input.
     *  2. Se vazio após trim, retorna string vazia.
     *  3. Para cada palavra (split por whitespace consecutivo):
     *     a. Se a palavra tem 2+ letras maiúsculas (detectadas via Unicode `\p{Lu}`),
     *        preserva-a intacta (suspeita de marca/acrônimo).
     *     b. Senão, aplica `mb_strtolower()` e, se for a primeira palavra da label,
     *        capitaliza a primeira letra via `mb_strtoupper()`.
     *  4. Junta as palavras processadas com espaço simples.
     *  5. Se o comprimento final exceder {@see MAX_LENGTH}, trunca e anexa "...".
     *
     * Idempotência: após o passo 3, toda palavra "comum" está em lowercase (exceto a
     * primeira letra da primeira palavra). Uma segunda passagem preserva marcas (já têm
     * 2+ maiúsculas) e não altera palavras já em lowercase — portanto o resultado é
     * idêntico ao da primeira passagem.
     *
     * Exemplos:
     *  - `""`           → `""`
     *  - `"  "`         → `""`
     *  - `"almoco"`     → `"Almoco"`
     *  - `"café"`       → `"Café"`
     *  - `"ALMOÇO"`     → `"Almoço"`    (normaliza uppercase → lowercase + capital first)
     *  - `"casa da praia"` → `"Casa da praia"`
     *  - `"iFood"`      → `"iFood"`     (marca preservada)
     *  - `"PIX"`        → `"PIX"`       (acrônimo preservado)
     *  - `"iPhone"`     → `"iPhone"`    (marca preservada)
     *  - `"ifood"`      → `"Ifood"`     (não é marca → P1)
     *  - `"pix"`        → `"Pix"`       (não é acrônimo → P1)
     *  - `"pizza 🍕"`   → `"Pizza 🍕"`  (emoji preservado)
     *
     * @param  string  $label  Label bruta (do LLM, do usuário ou do banco de dados).
     * @return string Label formatada no padrão canônico, ou string vazia.
     */
    public static function format(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return '';
        }

        // Split por whitespace consecutivo (regex Unicode-safe).
        $words = preg_split('/\s+/u', $label) ?: [];
        if ($words === []) {
            return '';
        }

        $result = [];
        $isFirstWord = true;

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $result[] = self::formatWord($word, $isFirstWord);
            $isFirstWord = false;
        }

        $formatted = implode(' ', $result);

        // Trunca se exceder MAX_LENGTH.
        if (mb_strlen($formatted) > self::MAX_LENGTH) {
            $formatted = mb_substr($formatted, 0, self::MAX_LENGTH - 3).'...';
        }

        return $formatted;
    }

    /**
     * Deduplica um array de labels por identidade fold-insensitive.
     *
     * Usa {@see TextNormalizer::fold()} como chave de identidade: labels que
     * diferem apenas em capitalização ou acentuação são consideradas a mesma
     * e apenas a primeira ocorrência é mantida. Labels cujo fold resulta em
     * string vazia são descartadas.
     *
     * Consumido como defesa em profundidade pelos extratores
     * ({@see DeepSeekService}, {@see GeminiService})
     * e pela action de sugestão ({@see SuggestLabelsLLM}) para
     * garantir que o LLM não devolva variantes da mesma label
     * (ex.: ["Almoço", "almoco", "ALMOCO"] → ["Almoço"]).
     *
     * @param  list<string>  $labels  Labels já formatadas (via {@see format()}).
     * @return list<string> Labels sem duplicatas fold-equivalentes.
     */
    public static function deduplicate(array $labels): array
    {
        $seen = [];
        $deduped = [];
        foreach ($labels as $label) {
            $key = TextNormalizer::fold($label);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $label;
        }

        return array_values($deduped);
    }

    /**
     * Formata uma palavra individual.
     *
     * Regra P7 (marcas/acrônimos):
     *  - Se a palavra contém letras maiúsculas E minúsculas (capitalização
     *    mista), é uma marca/produto com ortografia intencional — preserva
     *    intacta. Ex.: "iFood", "iPhone", "Netflix".
     *  - Se a palavra é toda maiúscula E tem 3 ou menos caracteres, é
     *    provavelmente um acrônimo — preserva intacta. Ex.: "PIX", "NFL".
     *  - Se a palavra é toda maiúscula com 4+ caracteres, é uma palavra
     *    comum digitada em caps — aplica P1 normalmente.
     *    Ex.: "ALMOÇO" → "Almoço".
     *
     * Regra P1: lowercase + (se primeira palavra da label) capitalizar a
     * primeira letra — Sentence Case.
     *
     * @param  string  $word  Palavra isolada (sem espaços).
     * @param  bool  $isFirstWord  Se esta é a primeira palavra da label.
     * @return string Palavra formatada.
     */
    private static function formatWord(string $word, bool $isFirstWord): string
    {
        $upperCount = preg_match_all('/\p{Lu}/u', $word);
        $lowerCount = preg_match_all('/\p{Ll}/u', $word);

        // P7 — Capitalização mista (marca): tem pelo menos 1 maiúscula
        // E pelo menos 1 minúscula → preserva intacta ("iFood", "iPhone").
        if ($upperCount !== false && $lowerCount !== false && $upperCount >= 1 && $lowerCount >= 1) {
            return $word;
        }

        // P7b — Acrônimo curto: toda maiúscula E 3 ou menos caracteres
        // ("PIX", "NFL"). Palavras comuns em caps ("ALMOÇO") são mais
        // longas e devem ser normalizadas.
        $wordLen = mb_strlen($word);
        if ($upperCount !== false && $lowerCount === 0 && $upperCount >= 2 && $wordLen <= 3) {
            return $word;
        }

        // Regra P1: lowercase + (se primeira palavra) capitalizar primeira letra.
        $lowered = mb_strtolower($word);

        if ($isFirstWord && $lowered !== '') {
            $firstChar = mb_substr($lowered, 0, 1);
            $rest = mb_substr($lowered, 1);

            return mb_strtoupper($firstChar).$rest;
        }

        return $lowered;
    }
}
