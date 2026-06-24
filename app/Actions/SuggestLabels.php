<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\Google\FirestoreService;
use App\Support\Stopwords;
use App\Support\TextNormalizer;

/**
 * Heurística PHP (sem LLM) para sugestão de labels — M8.
 *
 * @deprecated no fluxo principal desde a refatoração de labels — preservada para reuso futuro.
 *
 * Implementa o algoritmo definido em `docs/04-clarificacoes.md` §4 e em
 * `docs/06-plano-implementacao.md` §11 (M8.1). O objetivo é sugerir
 * etiquetas relevantes para uma transação **antes** do usuário confirmar,
 * combinando duas fontes de sinal:
 *
 *  1. **Histórico de uso** (`labels/{name}` ordenado por `use_count DESC`):
 *     labels que o próprio usuário já usou em transações anteriores —
 *     portanto têm valor preditivo comprovado. **Prioritário.**
 *  2. **Keywords da descrição** (Stopwords::extractKeywords): substantivos
 *     extraídos da descrição, normalizados e sem stopwords. Captura labels
 *     que ainda não estão no histórico mas são semanticamente óbvias
 *     ("pizza", "iFood", "energia").
 *
 * Regra de merge (especificada na §4):
 *
 *  - **Fase A — Histórico** (já ordenado por `use_count DESC`): adiciona
 *    cada label que NÃO esteja em `$existingLabels`, até `MAX_SUGGESTED_LABELS`
 *    ou fim da lista.
 *  - **Fase B — Keywords** (FIFO na ordem em que aparecem na descrição):
 *    preenche as vagas restantes com keywords que não estejam em
 *    `$existingLabels` nem já adicionadas na Fase A.
 *  - Se **ambas** as fontes estão vazias, devolve array vazio.
 *    **Nunca inventa labels** — uma sugestão vazia é preferível a ruído.
 *
 * Por que histórico antes de keywords?
 *  - Labels recorrentes são rotuladas pelo próprio usuário como "úteis"
 *    (ele as escolheu ≥1 vez). É o sinal mais forte de relevância.
 *  - Keywords são chutes educados: "energia" pode ser label boa, mas
 *    "luz" pode ser o termo que o usuário realmente usa. Histórico ganha.
 *
 * A categoria passada como parâmetro é atualmente **ignorada** pelo
 * histórico (a spec §5 define o schema `labels/` sem `category`). O
 * parâmetro existe na assinatura para preparar a evolução futura (vide
 * nota no FirestoreService::incrementLabelUse — ambiguidade da spec).
 */
final class SuggestLabels
{
    /** Teto de sugestões devolvidas (spec §4). */
    public const int MAX_SUGGESTED_LABELS = 5;

    /** Comprimento mínimo de uma keyword (spec §4) — usado por Stopwords. */
    public const int MIN_TOKEN_LENGTH = 3;

    /** Quantos labels top olhar do Firestore (spec §4). */
    public const int HISTORY_LIMIT = 10;

    public function __construct(
        private readonly FirestoreService $firestore,
    ) {}

    /**
     * Sugere até {@see MAX_SUGGESTED_LABELS} labels para uma transação.
     *
     * O parâmetro `$category` é reservado para evolução futura (filtrar o
     * histórico por categoria, quando a spec incluir esse campo no schema).
     * Por ora, a heurística usa o histórico **global** e as keywords da
     * descrição.
     *
     * @param  string|null  $description  Descrição da transação (fonte das keywords).
     * @param  string|null  $category  Categoria final (reservado p/ evolução; ignorado).
     * @param  list<string>  $existingLabels  Labels já presentes no DTO — não sugeridas.
     * @return list<string> Lista de sugestões (≤ 5). Pode ser vazia.
     */
    public function suggest(
        ?string $description,
        ?string $category = null,
        array $existingLabels = [],
    ): array {
        $existingSet = [];
        foreach ($existingLabels as $l) {
            // Folds tudo para garantir dedup case-insensitive e sem-acento.
            // Se o caller já passou normalizado, fold() é idempotente.
            $existingSet[TextNormalizer::fold($l)] = true;
        }

        $result = [];
        $resultSet = [];

        // FASE A — Histórico. Firestore já devolve ordenado por use_count DESC.
        $history = $this->firestore->getTopLabels(self::HISTORY_LIMIT);

        foreach ($history as $row) {
            $name = (string) ($row['data']['name'] ?? $row['id']);

            if ($name === '') {
                continue;
            }

            $key = TextNormalizer::fold($name);

            if (isset($existingSet[$key]) || isset($resultSet[$key])) {
                continue;
            }

            $result[] = $name;
            $resultSet[$key] = true;

            if (count($result) >= self::MAX_SUGGESTED_LABELS) {
                return $result;
            }
        }

        // FASE B — Keywords (FIFO da extração de Stopwords).
        $keywords = Stopwords::extractKeywords($description ?? '', self::MIN_TOKEN_LENGTH);

        foreach ($keywords as $kw) {
            if (count($result) >= self::MAX_SUGGESTED_LABELS) {
                break;
            }

            $key = TextNormalizer::fold($kw);

            if ($key === '' || isset($existingSet[$key]) || isset($resultSet[$key])) {
                continue;
            }

            $result[] = $kw;
            $resultSet[$key] = true;
        }

        return $result;
    }
}
