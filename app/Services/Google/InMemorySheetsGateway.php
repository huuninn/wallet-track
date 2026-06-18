<?php

declare(strict_types=1);

namespace App\Services\Google;

/**
 * Implementação fake de {@see SheetsGateway} para uso em testes (M6).
 *
 * Mantém as linhas da aba principal em um array PHP simples em memória
 * (índice 0 = cabeçalho, índices 1+ = dados) e os ranges auxiliares
 * (ex.: "Categorias!A1:B10") em um mapa separado. **Não há I/O nem rede** —
 * é a base que permite rodar {@see SheetsServiceTest} e {@see SyncSheetTest}
 * em milissegundos, sem chamar a Sheets API real.
 *
 * Semântica das operações:
 *
 *  - `getHeaderRow`: devolve a linha 0 ou null se a aba está vazia.
 *  - `writeHeaderRow`: sobrescreve a linha 0 (cabeçalho).
 *  - `appendRow`: empilha a linha após a última posição ocupada.
 *  - `writeAll`: substitui integralmente as linhas do range informado
 *    (equivalente em memória do clear+update da implementação real).
 *
 * Métodos de inspeção (`rows()`, `allRanges()`) expõem o estado interno
 * para asserções diretas nos testes.
 */
final class InMemorySheetsGateway implements SheetsGateway
{
    /** @var list<list<mixed>> Linhas da aba principal (índice 0 = cabeçalho). */
    private array $rows = [];

    /** @var array<string, list<list<mixed>>> Range A1 => linhas escritas. */
    private array $ranges = [];

    /**
     * Linhas da aba principal (índice 0 = cabeçalho).
     *
     * @return list<list<mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Mapa de ranges auxiliares escritos via writeAll (range A1 => linhas).
     *
     * @return array<string, list<list<mixed>>>
     */
    public function allRanges(): array
    {
        return $this->ranges;
    }

    public function getHeaderRow(): ?array
    {
        $header = $this->rows[0] ?? null;

        // Linha vazia (array vazio) conta como "sem cabeçalho" — espelha o
        // contrato da interface (null se vazia) e simplifica ensureHeaders().
        return $header === null || $header === [] ? null : $header;
    }

    public function writeHeaderRow(array $headers): void
    {
        $this->rows[0] = array_values($headers);
    }

    public function appendRow(array $row): void
    {
        $this->rows[] = array_values($row);
    }

    public function writeAll(string $range, array $rows): void
    {
        $this->ranges[$range] = array_values($rows);
    }
}
