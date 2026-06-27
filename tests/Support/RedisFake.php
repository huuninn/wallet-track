<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Fake in-memory para a facade Redis (Illuminate\Support\Facades\Redis).
 *
 * Implementa os comandos de hash usados pelo {@see \App\Services\Store\WalletStore}
 * (hgetall, hmset, hset, hsetnx, hincrby, hdel, del, expire) usando arrays
 * PHP como storage. Injete via `Redis::swap(new RedisFake)`.
 *
 * Limitações:
 *  - Não implementa TTL real (expire() apenas registra a chamada).
 *  - Valores são sempre strings (como no Redis real).
 *  - hgetall devolve array vazio para chave inexistente.
 */
final class RedisFake
{
    /**
     * Storage principal: key → [field → value].
     *
     * @var array<string, array<string, string>>
     */
    public static array $storage = [];

    /**
     * Registro de chamadas expire(): key → ttl.
     *
     * @var array<string, int>
     */
    public static array $expiry = [];

    public static function flush(): void
    {
        self::$storage = [];
        self::$expiry = [];
    }

    public function hgetall(string $key): array
    {
        return self::$storage[$key] ?? [];
    }

    public function hmset(string $key, array $data): void
    {
        foreach ($data as $field => $value) {
            self::$storage[$key][$field] = (string) $value;
        }
    }

    public function hset(string $key, string $field, string $value): int
    {
        $alreadyExists = isset(self::$storage[$key][$field]);
        self::$storage[$key][$field] = $value;

        return $alreadyExists ? 0 : 1;
    }

    public function hsetnx(string $key, string $field, string $value): int
    {
        if (isset(self::$storage[$key][$field])) {
            return 0;
        }

        self::$storage[$key][$field] = $value;

        return 1;
    }

    public function hincrby(string $key, string $field, int $increment): int
    {
        $current = isset(self::$storage[$key][$field])
            ? (int) self::$storage[$key][$field]
            : 0;
        $newValue = $current + $increment;
        self::$storage[$key][$field] = (string) $newValue;

        return $newValue;
    }

    public function hdel(string $key, string $field): int
    {
        if (! isset(self::$storage[$key][$field])) {
            return 0;
        }

        unset(self::$storage[$key][$field]);

        return 1;
    }

    public function del(string $key): int
    {
        if (! isset(self::$storage[$key])) {
            return 0;
        }

        unset(self::$storage[$key]);

        return 1;
    }

    public function expire(string $key, int $ttl): bool
    {
        self::$expiry[$key] = $ttl;

        return true;
    }
}
