<?php

declare(strict_types=1);

namespace App\Services\Google;

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use RuntimeException;

/**
 * Implementação real de {@see FirestoreGateway} sobre `FirestoreClient`.
 *
 * Traduz operações de alto nível (criar/set/merge/get/update/query/delete)
 * para a API fluente do SDK oficial. Foco em duas normalizações importantes:
 *
 *  **Timestamps** — O serviço troca timestamps como strings ISO 8601 UTC.
 *  Aqui convertemos string → {@see Timestamp} ao escrever e Timestamp →
 *  string ISO ao ler, mantendo o serviço agnóstico ao SDK.
 *
 *  **Transações** — Quando dentro de `transaction()`, as operações de
 *  leitura/escrita usam o objeto {@see Transaction} ativo em vez da
 *  leitura/escrita "fora de transação" padrão. Isto é o que garante a
 *  semântica atômica de read-modify-write (ex.: `incrementLabelUse`).
 *
 * Esta classe **não deve ser instanciada em testes**: o construtor exige
 * credenciais Google válidas e abre canal gRPC. Testes usam
 * {@see InMemoryFirestoreGateway}.
 */
final class CloudFirestoreGateway implements FirestoreGateway
{
    /**
     * Lista de campos de timestamp que sofrem conversão automática string↔Timestamp.
     *
     * Convenção de nomenclatura do schema (spec §5): campos terminados em
     * `_at` são timestamps. Para evitar conversion de strings que coincidem
     * com este padrão mas não são timestamps (nenhum no schema atual), usamos
     * uma lista explícita de campos conhecidos.
     */
    private const array TIMESTAMP_FIELDS = [
        'created_at',
        'updated_at',
        'sync_last_attempt_at',
        'last_used_at',
    ];

    /**
     * Operador de query suportado pela interface fluent. Mapeamos os
     * operadores curtos recebidos pelo gateway para a forma canônica
     * esperada pelo SDK.
     */
    private const array OPERATOR_MAP = [
        '==' => '==',
        '=' => '==',
        '!=' => '!=',
        '>' => '>',
        '>=' => '>=',
        '<' => '<',
        '<=' => '<=',
        'in' => 'IN',
        'array-contains' => 'array-contains',
        'array-contains-any' => 'array-contains-any',
    ];

    private ?Transaction $activeTransaction = null;

    public function __construct(private readonly FirestoreClient $client) {}

    public function createDocument(string $collection, array $data): string
    {
        $ref = $this->client->collection($collection)->newDocument();

        // Quando dentro de transaction(), o commit só ocorre ao fim bem-
        // sucedido da closure — caso contrário, $ref->create() comete
        // imediatamente. Sem este roteamento, escritas "dentro" de
        // transaction() iam direto ao servidor, quebrando atomicidade
        // (lost update em read-modify-write concorrente).
        if ($this->activeTransaction !== null) {
            $this->activeTransaction->create($ref, $this->encode($data));
        } else {
            $ref->create($this->encode($data));
        }

        return $ref->id();
    }

    public function setDocument(string $collection, string $id, array $data): void
    {
        $ref = $this->client->collection($collection)->document($id);

        if ($this->activeTransaction !== null) {
            $this->activeTransaction->set($ref, $this->encode($data));
        } else {
            $ref->set($this->encode($data));
        }
    }

    public function mergeDocument(string $collection, string $id, array $data): void
    {
        $ref = $this->client->collection($collection)->document($id);

        if ($this->activeTransaction !== null) {
            $this->activeTransaction->set($ref, $this->encode($data), ['merge' => true]);
        } else {
            $ref->set($this->encode($data), ['merge' => true]);
        }
    }

    public function getDocument(string $collection, string $id): ?array
    {
        $ref = $this->client->collection($collection)->document($id);

        $snap = $this->activeTransaction !== null
            ? $this->activeTransaction->snapshot($ref)
            : $ref->snapshot();

        if (! $snap->exists()) {
            return null;
        }

        return $this->decode($snap->data());
    }

    public function updateFields(string $collection, string $id, array $fields): void
    {
        $updates = [];
        foreach ($fields as $path => $value) {
            $updates[] = [
                'path' => $path,
                'value' => $this->encodeValue($path, $value),
            ];
        }

        $ref = $this->client->collection($collection)->document($id);

        if ($this->activeTransaction !== null) {
            $this->activeTransaction->update($ref, $updates);
        } else {
            $ref->update($updates);
        }
    }

    public function incrementField(string $collection, string $id, string $field, int $amount = 1): void
    {
        $ref = $this->client->collection($collection)->document($id);
        $sentinel = FieldValue::increment($amount);

        // Mesmo FieldValue::increment sendo atomic server-side, precisamos
        // enfileirar a transform na Transaction ativa para participar do
        // commit/rollback conjunto (sem isso, um increment "dentro" de
        // transaction seria cometido imediatamente, quebrando atomicidade
        // quando outras escritas da mesma closure falham).
        if ($this->activeTransaction !== null) {
            $this->activeTransaction->update($ref, [['path' => $field, 'value' => $sentinel]]);
        } else {
            $ref->update([['path' => $field, 'value' => $sentinel]]);
        }
    }

    public function deleteDocument(string $collection, string $id): void
    {
        $ref = $this->client->collection($collection)->document($id);

        if ($this->activeTransaction !== null) {
            $this->activeTransaction->delete($ref);
        } else {
            $ref->delete();
        }
    }

    public function query(string $collection, array $wheres = [], array $orderBys = [], ?int $limit = null): array
    {
        $query = $this->client->collection($collection);

        foreach ($wheres as $where) {
            $operator = self::OPERATOR_MAP[$where['op']] ?? $where['op'];
            $query = $query->where($where['field'], $operator, $where['value']);
        }

        foreach ($orderBys as $orderBy) {
            $direction = strtoupper($orderBy['direction']) === 'DESC'
                ? 'DESC'
                : 'ASC';
            $query = $query->orderBy($orderBy['field'], $direction);
        }

        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        $documents = $query->documents();
        $results = [];

        foreach ($documents as $document) {
            if (! $document->exists()) {
                continue;
            }

            $results[] = [
                'id' => $document->id(),
                'data' => $this->decode($document->data()),
            ];
        }

        return $results;
    }

    public function transaction(callable $fn): mixed
    {
        $result = null;

        $this->client->runTransaction(function (Transaction $tx) use ($fn, &$result): void {
            $previous = $this->activeTransaction;
            $this->activeTransaction = $tx;

            try {
                $result = $fn($this);
            } finally {
                $this->activeTransaction = $previous;
            }
        });

        return $result;
    }

    /**
     * Codifica o array PHP para gravação, convertendo os campos de timestamp
     * conhecidos de string ISO para {@see Timestamp}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function encode(array $data): array
    {
        $encoded = [];
        foreach ($data as $key => $value) {
            $encoded[$key] = $this->encodeValue((string) $key, $value);
        }

        return $encoded;
    }

    /**
     * Codifica um valor individual (usado por encode() e updateFields()).
     */
    private function encodeValue(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($field, self::TIMESTAMP_FIELDS, true) && is_string($value)) {
            return $this->parseTimestamp($value);
        }

        return $value;
    }

    /**
     * Converte strings ISO/Timestamp de volta do formato nativo do Firestore.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function decode(array $data): array
    {
        foreach (self::TIMESTAMP_FIELDS as $field) {
            if (! isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            if ($value instanceof Timestamp) {
                $data[$field] = $value->get()->format(Timestamp::FORMAT);
            }
        }

        return $data;
    }

    /**
     * Cria um {@see Timestamp} a partir de uma string ISO 8601 (UTC).
     *
     * @throws RuntimeException Quando a string não pode ser parseada.
     */
    private function parseTimestamp(string $iso): Timestamp
    {
        try {
            return new Timestamp(new \DateTimeImmutable($iso));
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Falha ao parsear timestamp ISO no campo Firestore: {$iso}",
                previous: $e,
            );
        }
    }
}
