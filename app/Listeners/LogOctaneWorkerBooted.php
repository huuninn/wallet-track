<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\WorkerStarting;

/**
 * Listener Octane que loga o boot de cada worker, confirmando via Cloud
 * Logging que o pipeline Octane está ativo.
 *
 * ## Propósito
 *
 * Este listener serve como **prova positiva** de que o Octane está em
 * execução. Ele só dispara quando o evento {@see WorkerStarting} do
 * Laravel Octane é emitido — no modo `php_server` legacy (sem Octane),
 * este evento jamais é emitido e este listener jamais roda. Portanto,
 * a presença do log `"Octane worker booted"` no Cloud Logging é
 * evidência direta de que o pipeline Octane está ativo.
 *
 * ## Frequência esperada
 *
 *  - **1× no start**: quando o servidor Octane inicia e cria o pool
 *    inicial de workers (ex.: 4 workers = 4 entradas de log).
 *  - **1× a cada `max_requests`**: quando um worker atinge o limite de
 *    requests configurado (ex.: `max_requests=1000`), ele é reciclado
 *    e um novo worker é criado, gerando nova entrada de log.
 *
 * ## Campos logados
 *
 *  - `app_instance`: ID interno da Application Laravel (obtido via
 *    `spl_object_id($event->app)`), útil para correlacionar logs
 *    do mesmo worker durante troubleshooting.
 *  - `pid`: PID do processo Linux (`getmypid()`), útil para
 *    correlacionar com métricas de sistema (CPU, memória) e logs do
 *    FrankenPHP/Docker.
 */
final class LogOctaneWorkerBooted
{
    public function handle(WorkerStarting $event): void
    {
        Log::info('Octane worker booted', [
            'app_instance' => spl_object_id($event->app),
            'pid' => getmypid(),
        ]);
    }
}
