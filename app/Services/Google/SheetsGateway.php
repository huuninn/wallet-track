<?php

declare(strict_types=1);

namespace App\Services\Google;

/**
 * Abstração de escrita/leitura da planilha Google Sheets (M6).
 *
 * **Por que um gateway?** Pelo mesmo motivo do {@see FirestoreGateway}: o SDK
 * oficial (`Google\Service\Sheets`) expõe objetos `ValueRange`/resources com
 * encadeamento (`spreadsheets_values->get/append/update`), o que torna mockar
 * a API real frágil e acoplado à versão do `google/apiclient`.
 *
 * Esta interface estreita o contrato a **operações atômicas de alto nível**
 * que devolvem tipos primitivos (arrays PHP). Implementações:
 *
 *   - {@see GoogleSheetsGateway}     → wrap do `Google\Service\Sheets` real.
 *   - {@see InMemorySheetsGateway}   → store em array PHP (testes; sem rede).
 *
 * **Escopo**: opera sobre a aba principal (`config('google.sheets.sheet_name')`)
 * para os métodos de cabeçalho/append. `writeAll()` recebe um range explícito
 * (em notação A1, ex.: `Categorias!A1:B10`) para sobrescrever abas auxiliares.
 *
 * **USER_ENTERED** é responsabilidade da implementação real traduzir o option
 * correto: append usa `USER_ENTERED` (datas/números interpretados pelo Sheets);
 * cabeçalho/categorias usam `RAW` (texto literal). O serviço só trafega arrays.
 */
interface SheetsGateway
{
    /**
     * Lê a linha 1 (cabeçalho) da aba principal.
     *
     * @return list<mixed>|null A linha como array de colunas, ou null se a
     *                          aba/range está vazia (sem cabeçalho escrito).
     */
    public function getHeaderRow(): ?array;

    /**
     * Sobrescreve a linha 1 (cabeçalho) da aba principal.
     *
     * @param  list<mixed>  $headers  Valores das colunas A, B, C, ...
     */
    public function writeHeaderRow(array $headers): void;

    /**
     * Adiciona uma linha na próxima posição livre da aba principal
     * (INSERT_ROWS via append).
     *
     * @param  list<mixed>  $row  Valores das colunas A..H na ordem do schema (8 colunas).
     */
    public function appendRow(array $row): void;

    /**
     * Deleta uma coluna inteira pelo índice (0-based).
     *
     * Caso de uso: remoção da coluna G (Origem) após migração para 8 colunas.
     *
     * @param  int  $sheetId  ID numérico da sheet (0 = primeira aba).
     * @param  int  $columnIndex  Índice 0-based da coluna a deletar.
     */
    public function deleteColumn(int $sheetId, int $columnIndex): void;

    /**
     * Sobrescreve integralmente um range qualquer (notação A1).
     *
     * Limpa o range e escreve `$rows` a partir do canto superior esquerdo.
     * Caso de uso: sincronizar a aba auxiliar de Categorias (somente escrita).
     *
     * @param  string  $range  Ex.: "Categorias!A1:B10".
     * @param  list<list<mixed>>  $rows  Lista de linhas (cada linha = colunas).
     */
    public function writeAll(string $range, array $rows): void;
}
