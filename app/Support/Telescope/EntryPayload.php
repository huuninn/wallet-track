<?php

declare(strict_types=1);

namespace App\Support\Telescope;

use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Throwable;

/**
 * Value object com helpers para estruturar payloads dos 3 watchers customizados
 * (Firestore, Gemini Image, DeepSeek Chat).
 *
 * Centraliza a formatação de campos comuns (exception, latência) e o ponto
 * único de entrada no Telescope (`recordEvent`), eliminando a duplicação de
 * `Telescope::recordEvent(IncomingEntry::make(...)->tags(...))` nos 3 decorators.
 */
final class EntryPayload
{
    /**
     * Grava um evento no Telescope (wrapper único para os 3 decorators).
     *
     * Centraliza a chamada `Telescope::recordEvent(IncomingEntry::make(...)->tags(...))`
     * que estava duplicada nos 3 decorators. Se o Telescope estiver pausado ou
     * desabilitado, `Telescope::recordEvent()` é internamente no-op — nenhum
     * efeito colateral.
     *
     * @param  array<string, mixed>  $content
     * @param  list<string>  $tags
     */
    public static function recordEvent(array $content, array $tags): void
    {
        Telescope::recordEvent(
            IncomingEntry::make($content)->tags($tags)
        );
    }

    /**
     * Formata uma exceção para o campo `exception` do entry do Telescope.
     *
     * Retorna `null` se a exceção for nula (caso de sucesso). Caso contrário,
     * devolve um array com `class`, `message` e `code` (cast para int) —
     * suficiente para diagnosticar a falha sem serializar o stack trace
     * inteiro (que pode ser enorme e polui a visualização na UI do Telescope).
     *
     * @return array{class: string, message: string, code: int}|null
     */
    public static function formatException(?Throwable $e): ?array
    {
        if ($e === null) {
            return null;
        }

        return [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => (int) $e->getCode(),
        ];
    }

    /**
     * Converte nanossegundos (saída de `hrtime(true)`) para milissegundos.
     *
     * `hrtime(true)` retorna int em nanossegundos. Para entrada no entry
     * do Telescope, convertemos para ms (divisão inteira por 1_000_000)
     * para que humanos e queries SQLite consigam ler o número.
     */
    public static function nsToMs(int $nanoseconds): int
    {
        return (int) ($nanoseconds / 1_000_000);
    }
}
