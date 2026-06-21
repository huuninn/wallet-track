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
     * **Estrutura esperada pela UI Vue:**
     *
     * A UI do Telescope em `resources/js/screens/events/index.vue` e
     * `preview.vue` espera que toda entry do tipo `event` tenha:
     *
     *   - `content.name`: string — exibida na coluna "Name".
     *   - `content.listeners`: array — acessado via `.length` na listagem
     *     SEM fallback para `undefined`, então **deve** estar presente.
     *   - `content.broadcast`: truthy opcional — exibe badge "Broadcast".
     *   - `content.payload`: mixed — exibido na aba "Event Data" via
     *     `vue-json-pretty` no preview da entry.
     *
     * Sem essa normalização, a UI quebra com `TypeError: can't access
     * property "length", r.entry.content.listeners is undefined` e a aba
     * Events fica presa em "scanning...".
     *
     * Por isso, este método recebe o conteúdo "cru" (com `name` + chaves
     * extras do decorator) e o normaliza para o formato esperado pela UI:
     * `name` fica no topo, tudo o mais vira `payload`, e `listeners`/`
     * broadcast` recebem defaults seguros.
     *
     * Se o Telescope estiver pausado ou desabilitado, `Telescope::recordEvent()`
     * é internamente no-op — nenhum efeito colateral.
     *
     * @param  array<string, mixed>  $content  Deve conter ao menos `name`.
     * @param  list<string>  $tags
     */
    public static function recordEvent(array $content, array $tags): void
    {
        $name = $content['name'] ?? '(unknown)';
        $listeners = $content['listeners'] ?? [];
        $broadcast = $content['broadcast'] ?? null;

        // Remove chaves que têm significado especial na UI do Telescope,
        // deixando o resto como payload propriamente dito.
        unset($content['name'], $content['listeners'], $content['broadcast']);

        Telescope::recordEvent(
            IncomingEntry::make([
                'name' => $name,
                'listeners' => $listeners,
                'broadcast' => $broadcast,
                'payload' => $content,
            ])->tags($tags)
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
