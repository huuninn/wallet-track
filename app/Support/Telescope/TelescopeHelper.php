<?php

declare(strict_types=1);

namespace App\Support\Telescope;

use App\Providers\TelescopeServiceProvider;
use Illuminate\Support\Facades\App;

/**
 * Helper estático que centraliza a condição de ativação do Telescope.
 *
 * Portão único consumido pelos 3 service providers instrumentados
 * (FirestoreServiceProvider, GeminiServiceProvider, DeepSeekServiceProvider)
 * e pelo {@see TelescopeServiceProvider}. Garante que a
 * decisão "Telescope está ativo?" é tomada num único lugar — sem chance
 * de um provider divergir dos outros.
 *
 * **Dois níveis de verificação** (defesa em profundidade):
 *  1. `config('telescope.enabled') === true` — master switch controlado
 *     por `TELESCOPE_ENABLED` no `.env` (default `false`).
 *  2. `App::environment('local') === true` — segunda camada: mesmo com
 *     o flag ligado, o Telescope só instrumenta em ambiente `local`.
 *     **NUNCA** `staging` ou `production` (decisão do usuário: o app
 *     serve dados financeiros sensíveis; staging pode rodar em ambiente
 *     compartilhado, então telemetria rica só fica habilitada em `local`).
 *
 * **Por que NÃO verificar `Telescope::isRecording()` aqui?**
 *  Esse check é feito internamente pelo `Telescope::recordEvent()`.
 *  Se o Telescope estiver pausado (cache, schedule `pause`/`resume`),
 *  as entradas simplesmente não são persistidas — sem custo de manter
 *  o estado duplicado neste helper.
 */
final class TelescopeHelper
{
    /**
     * Indica se o Telescope está ativo para registrar entradas customizadas.
     *
     * Retorna `true` somente se AMBOS os níveis forem satisfeitos:
     *  - `config('telescope.enabled') === true`
     *  - `App::environment('local') === true`
     */
    public static function isActive(): bool
    {
        return config('telescope.enabled') === true
            && App::environment('local');
    }
}
