<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ordena items para exibição (Sheets coluna I, Telegram resumo).
 *
 * Regra (Decisão P4=c + PC1=a):
 *   1. Items COM subtotal: ordenados por subtotal ASC (crescente).
 *      Empate (mesmo subtotal): preserva ordem de entrada.
 *   2. Items SEM subtotal (null): ao final, em ordem de entrada.
 *
 * Combina os dois grupos: com-subtotal primeiro (ordenados),
 * sem-subtotal depois (ordem original).
 *
 * Stateless — usado por SheetsService e TransactionSummaryFormatter
 * para garantir a mesma ordenação nos dois destinos (CT-133).
 */
final class ItemsSorter
{
    /**
     * Ordena a lista de items conforme a regra de exibição.
     *
     * @param  list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>  $items
     * @return list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>
     */
    public function sort(array $items): array
    {
        if ($items === []) {
            return [];
        }

        // 1. Separa items COM subtotal dos SEM subtotal, preservando índice original.
        $withSubtotal = [];
        $withoutSubtotal = [];

        foreach ($items as $i => $item) {
            if ($item['subtotal'] !== null) {
                $withSubtotal[] = ['item' => $item, 'order' => $i];
            } else {
                $withoutSubtotal[] = ['item' => $item, 'order' => $i];
            }
        }

        // 2. Ordena COM subtotal por subtotal ASC. Empate → ordem original
        //    (usort é estável em PHP 8.0+, mas index explícito garante).
        usort($withSubtotal, static function (array $a, array $b): int {
            $cmp = $a['item']['subtotal'] <=> $b['item']['subtotal'];

            return $cmp !== 0 ? $cmp : ($a['order'] <=> $b['order']);
        });

        // 3. SEM subtotal: mantém ordem de entrada (reordena por order como garantia).
        usort($withoutSubtotal, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        // 4. Concatena: com-subtotal (ordenados) + sem-subtotal (ordem original).
        $ordered = array_merge(
            array_map(static fn (array $x): array => $x['item'], $withSubtotal),
            array_map(static fn (array $x): array => $x['item'], $withoutSubtotal),
        );

        // 5. Reindexa para list<array>.
        return array_values($ordered);
    }
}
