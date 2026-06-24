<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\TransactionData;

/**
 * Abstração para sugestão de labels via LLM dedicado (M2).
 *
 * Diferente da heurística PHP ({@see SuggestLabels}, deprecated),
 * esta action usa o próprio LLM (DeepSeek) com um prompt especializado para
 * selecionar, entre o catálogo do usuário e o contexto da transação, quais
 * labels são mais relevantes.
 *
 * O contrato é:
 *  - Recebe um {@see TransactionData} parcial (description, category, amount, type).
 *  - Recebe o catálogo top-N do usuário (display names).
 *  - Devolve até {@see config('labels.max_labels')} labels, capitalizadas.
 *  - NUNCA lança exceção: falha de LLM, JSON inválido, etc. → retorna `[]`.
 *
 * @see SuggestLabelsLLM Implementação concreta usada em produção.
 */
interface SuggestsLabels
{
    /**
     * Sugere labels para uma transação via LLM.
     *
     * @param  TransactionData  $dto  Draft parcial (com description/category/amount/type).
     * @param  list<string>  $labelCatalog  Top-N labels do usuário (display names).
     * @return list<string> Labels sugeridas (≤ max, capitalizadas). Pode ser vazia.
     */
    public function suggest(TransactionData $dto, array $labelCatalog = []): array;
}
