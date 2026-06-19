<?php

declare(strict_types=1);

namespace App\Services\Google;

/**
 * Implementação fake de {@see FirestoreGateway} para uso em testes.
 *
 * Mantém todos os documentos em um array PHP simples em memória, organizado
 * por `coleção => [id => data]`. **Não há I/O nem rede** — é a base que
 * permite rodar {@see FirestoreServiceTest} e {@see SeedCategoriesCommandTest}
 * em milissegundos, sem chamar o Firestore real.
 *
 * Semântica das operações:
 *
 *  - `createDocument`: gera um id aleatório (bin2hex(random_bytes(12))),
 *    como o Firestore faz na prática.
 *  - `setDocument`: overwrite total (substitui o conteúdo existente).
 *  - `mergeDocument`: deep merge 1 nível — campos novos são adicionados,
 *    existentes sobrescritos; arrays e maps entram por completo (não há
 *    merge recursivo em sub-arrays, salvo necessidade futura).
 *  - `updateFields`: seta campos individuais por path; aceita paths
 *    pontilhados ("a.b.c") criando sub-arrays aninhados.
 *  - `incrementField`: read-modify-write atômico em processo único.
 *  - `query`: filtros `==` e `!=` são totalmente suportados; demais
 *    operadores suportam string/number compare. Ordenação genérica por
 *    campo (ASC/DESC); limite aplicado ao final.
 *  - `transaction`: apenas invoca a closure (em processo único PHP, todo
 *    bloco síncrono já é "atômico"). Implementado para satisfação do
 *    contrato da interface.
 *
 * Timestamps: o serviço já troca timestamps como strings ISO; aqui apenas
 * guardamos e devolvemos as strings como estão.
 */
final class InMemoryFirestoreGateway implements FirestoreGateway
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $store = [];

    /**
     * Permite inspecionar o estado interno em testes (asserções diretas).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function raw(): array
    {
        return $this->store;
    }

    public function createDocument(string $collection, array $data): string
    {
        // Id pseudo-aleatório com 24 hex chars (mesmo padrão do Firestore
        // auto-id, embora aqui não precise ser exatamente igual).
        $id = bin2hex(random_bytes(12));

        $this->store[$collection][$id] = $data;

        return $id;
    }

    public function setDocument(string $collection, string $id, array $data): void
    {
        $this->store[$collection][$id] = $data;
    }

    public function mergeDocument(string $collection, string $id, array $data): void
    {
        $existing = $this->store[$collection][$id] ?? [];
        $this->store[$collection][$id] = $this->mergeDeep($existing, $data);
    }

    public function getDocument(string $collection, string $id): ?array
    {
        return $this->store[$collection][$id] ?? null;
    }

    public function updateFields(string $collection, string $id, array $fields): void
    {
        if (! isset($this->store[$collection][$id])) {
            $this->store[$collection][$id] = [];
        }

        foreach ($fields as $path => $value) {
            $this->setPath($this->store[$collection][$id], $path, $value);
        }
    }

    public function incrementField(string $collection, string $id, string $field, int $amount = 1): void
    {
        if (! isset($this->store[$collection][$id])) {
            $this->store[$collection][$id] = [];
        }

        $current = $this->store[$collection][$id][$field] ?? 0;
        $current = is_int($current) ? $current : 0;
        $this->store[$collection][$id][$field] = $current + $amount;
    }

    /**
     * Remove um campo do documento (com suporte a paths pontilhados).
     *
     * **Diferença vs CloudFirestoreGateway**: esta implementação NÃO lança
     * se o documento não existe (early return silencioso), enquanto o gateway
     * real lança exceção via SDK `$ref->update()`. O caller
     * (FirestoreService::setSession) nunca chama deleteField sem antes ter
     * criado o documento via mergeDocument, portanto a diferença é inócua
     * em runtime — mas a assimetria é intencional para testabilidade.
     */
    public function deleteField(string $collection, string $id, string $field): void
    {
        if (! isset($this->store[$collection][$id])) {
            return;
        }

        // Suporta paths pontilhados análogos a updateFields() — ex.: "a.b.c"
        // remove apenas a sub-chave mantendo o resto do sub-map intacto.
        $parts = explode('.', $field);
        $target = &$this->store[$collection][$id];

        foreach ($parts as $i => $part) {
            if (! is_array($target) || ! array_key_exists($part, $target)) {
                return;
            }

            if ($i === count($parts) - 1) {
                unset($target[$part]);

                return;
            }

            $target = &$target[$part];
        }
    }

    public function deleteDocument(string $collection, string $id): void
    {
        unset($this->store[$collection][$id]);
    }

    public function query(string $collection, array $wheres = [], array $orderBys = [], ?int $limit = null): array
    {
        $docs = $this->store[$collection] ?? [];

        $results = [];
        foreach ($docs as $id => $data) {
            if ($this->matchesWheres($data, $wheres)) {
                $results[] = ['id' => (string) $id, 'data' => $data];
            }
        }

        if ($orderBys !== []) {
            usort($results, $this->buildComparator($orderBys));
        }

        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }

    public function transaction(callable $fn): mixed
    {
        return $fn($this);
    }

    /**
     * Deep merge 1 nível: chaves novas são adicionadas, chaves existentes
     * sobrescritas. Arrays entram por completo (sem merge recursivo em
     * arrays dentro de arrays), espelhando `set(['merge' => true])` do SDK.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Aplica os filtros `wheres` ao data do documento.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{field: string, op: string, value: mixed}>  $wheres
     */
    private function matchesWheres(array $data, array $wheres): bool
    {
        foreach ($wheres as $where) {
            $field = $where['field'];
            $operator = $where['op'];
            $expected = $where['value'];
            $actual = $data[$field] ?? null;

            if (! $this->matchOperator($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function matchOperator(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual === $expected,
            '!=' => $actual !== $expected,
            '<' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            '<=' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            '>' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            '>=' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            default => false,
        };
    }

    /**
     * Monta o comparador usado pelo usort para aplicar todos os orderBys.
     *
     * @param  array<int, array{field: string, direction: string}>  $orderBys
     */
    private function buildComparator(array $orderBys): callable
    {
        return static function (array $a, array $b) use ($orderBys): int {
            foreach ($orderBys as $orderBy) {
                $field = $orderBy['field'];
                $direction = strtoupper($orderBy['direction']) === 'DESC' ? -1 : 1;

                $va = $a['data'][$field] ?? null;
                $vb = $b['data'][$field] ?? null;

                if ($va === $vb) {
                    continue;
                }

                if ($va === null) {
                    return $direction;
                }
                if ($vb === null) {
                    return -$direction;
                }

                // String e number são comparáveis nativamente; qualquer outro
                // tipo cai em comparação por (string).
                if (is_string($va) && is_string($vb)) {
                    return $direction * strcmp($va, $vb);
                }

                return $va > $vb ? $direction : -$direction;
            }

            return 0;
        };
    }

    /**
     * Seta valor por path pontilhado, criando sub-arrays aninhados.
     *
     * @param  array<string, mixed>  $array  (por referência)
     */
    private function setPath(array &$array, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $current = &$array;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
                break;
            }

            if (! isset($current[$part]) || ! is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
    }
}
