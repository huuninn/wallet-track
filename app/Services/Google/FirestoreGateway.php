<?php

declare(strict_types=1);

namespace App\Services\Google;

/**
 * Abstração de persistência Firestore usada por {@see FirestoreService}.
 *
 * **Por que um gateway?** O SDK oficial (`Google\Cloud\Firestore\FirestoreClient`)
 * expõe uma API fluente de encadeamento longo
 * (`$db->collection('x')->document('y')->snapshot()->data()`). Mockar essa
 * cadeia com Mockery é frágil ( Snapshot → CollectionReference →
 * DocumentReference → Transaction ), e qualquer pequena mudança de versão
 * do SDK quebra o mock.
 *
 * Esta interface estreita o contrato a **operações atômicas de alto nível**
 * que devolvem tipos primitivos (arrays PHP, strings). Implementações:
 *
 *   - {@see CloudFirestoreGateway}     → wrap do FirestoreClient real (M5+).
 *   - {@see InMemoryFirestoreGateway}  → store em array PHP (testes; sem rede).
 *
 * **Convenção de timestamps**: o serviço trabalha com timestamps como strings
 * ISO 8601 UTC (ex.: "2026-06-15T12:30:00.000000Z"). A implementação real
 * converte para dentro/fora de `Google\Cloud\Core\Timestamp`. A implementação
 * in-memory apenas guarda e devolve as strings como vieram.
 *
 * **Convenção de retorno de documentos**: métodos que devolvem um documento
 * retornam `?array` (data do doc ou null se não existe). Métodos que listam
 * devolvem `list<array{id: string, data: array}>`.
 */
interface FirestoreGateway
{
    /**
     * Cria um novo documento com auto-id gerado pelo servidor.
     *
     * @param  array<string, mixed>  $data
     * @return string ID do documento criado.
     */
    public function createDocument(string $collection, array $data): string;

    /**
     * Cria ou substitui (overwrite total) um documento pelo id.
     *
     * @param  array<string, mixed>  $data
     */
    public function setDocument(string $collection, string $id, array $data): void;

    /**
     * Merge parcial (deep merge 1 nível) no documento. Cria se não existe.
     *
     * @param  array<string, mixed>  $data
     */
    public function mergeDocument(string $collection, string $id, array $data): void;

    /**
     * Lê o documento pelo id.
     *
     * @return array<string, mixed>|null Data ou null se não existe.
     */
    public function getDocument(string $collection, string $id): ?array;

    /**
     * Atualização por campos individuais (estilo `update()` do SDK).
     *
     * Útil para incrementar contadores atômicamente
     * (use {@see self::incrementField()} para isso) ou setar poucos campos
     * sem reescrever o doc inteiro. Documento deve existir.
     *
     * @param  array<string, mixed>  $fields  Mapa `field_path => value`.
     */
    public function updateFields(string $collection, string $id, array $fields): void;

    /**
     * Incrementa atômicamente um campo numérico (default 1).
     *
     * Em CloudFirestoreGateway, traduz para `FieldValue::increment($amount)`.
     * Em InMemoryFirestoreGateway, faz read-merge-write trivialmente atômico
     * em processo único.
     */
    public function incrementField(string $collection, string $id, string $field, int $amount = 1): void;

    /**
     * Remove um campo individual do documento (W-3 da revisão).
     *
     * Útil para limpar campos stale entre transições de estado — ex.: ao
     * sair de AWAITING_DATA/EDITION, o `awaiting_field` deve ser removido
     * em vez de setado como null (merge com null não apaga no Firestore).
     *
     * Em CloudFirestoreGateway, traduz para `FieldValue::delete()` do SDK
     * (sentinel especial aceito por `update()`). Em InMemoryFirestoreGateway,
     * faz `unset` na chave do array.
     */
    public function deleteField(string $collection, string $id, string $field): void;

    /**
     * Remove um documento. Não lança se o documento não existe.
     */
    public function deleteDocument(string $collection, string $id): void;

    /**
     * Lista documentos da coleção aplicando filtros, ordenação e limite.
     *
     * @param  array<int, array{field: string, op: string, value: mixed}>  $wheres
     * @param  array<int, array{field: string, direction: string}>  $orderBys  direction: 'ASC'|'DESC'
     * @return list<array{id: string, data: array<string, mixed>}>
     */
    public function query(string $collection, array $wheres = [], array $orderBys = [], ?int $limit = null): array;

    /**
     * Executa uma closure dentro de uma transação atômica.
     *
     * A closure recebe o próprio gateway; operações chamadas dentro dela
     * (getDocument, mergeDocument, etc.) participam da transação. Retorna
     * o que a closure devolver.
     *
     * Caso de uso principal: read-modify-write atômico em contadores
     * (ex.: incrementar `use_count` de labels considerando o valor atual).
     */
    public function transaction(callable $fn): mixed;
}
