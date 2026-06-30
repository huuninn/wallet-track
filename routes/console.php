<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule: Sync de transações pendentes (M5.1)
|--------------------------------------------------------------------------
| Substitui o endpoint HTTP /cron/sync-pending (Cloud Scheduler legacy).
| Roda a cada 5 min no Octane worker (via `php artisan schedule:work`
| ou systemd timer na VPS).

Opções:
  - everyFiveMinutes(): cadência do sync (igual ao Cloud Scheduler anterior)
  - withoutOverlapping(10): se a execução anterior ainda está rodando,
    skipa esta. 10 min de jitter = 2 ciclos de folga.
  - runInBackground(): não bloqueia o worker de schedule (permite que
    outros jobs agendados rodem concorrentemente).
  - onOneServer(): em deploy multi-instância, só um node executa
    (prevenção de race no lock de DB).
*/
Schedule::command('transactions:sync-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('sync-pending')
    ->onOneServer();
