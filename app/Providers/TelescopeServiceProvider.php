<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Telescope\TelescopeHelper;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Override;

/**
 * Service Provider customizado do Laravel Telescope.
 *
 * Estende o {@see TelescopeApplicationServiceProvider} (que carrega
 * watchers, registra middleware etc.) e adiciona:
 *
 *  1. **Conexão SQLite runtime** — a conexão `telescope` é registrada
 *     em {@see register()}, ANTES de qualquer uso. NÃO é adicionada ao
 *     `config/database.php` global (M11-respeitado: zero conexões no
 *     config global da app; o app é server-to-server e usa Firestore
 *     como única fonte de verdade).
 *
 *  2. **Gate IP whitelist** — o `Gate::define('viewTelescope')` padrão
 *     da upstream exige `User::email` em allowlist (que não faz sentido
 *     para nosso bot server-to-server). Substituímos por IP whitelist
 *     com defaults Docker-friendly (127.0.0.1, ::1, 172.16/12,
 *     192.168/16, 10/8) e override via `TELESCOPE_ALLOWED_IPS`.
 *
 *  3. **Env check `local`-only** — `boot()` faz early-return se o
 *     Telescope não estiver habilitado. Compatível com `local`-only
 *     decidido pelo usuário (NUNCA `staging` ou `production`).
 *
 *  4. **Schedule de pruning** — `telescope:prune --hours=168` diário
 *     (registrado em {@see registerSchedule()}).
 *
 *  5. **Hide sensitive request details** — em `local`, mantém
 *     visibilidade total (útil para debug); em outros ambientes,
 *     esconde `_token`, `cookie`, `x-csrf-token`, `x-xsrf-token`.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * Ordem de operações:
     *  1. Registra a conexão SQLite runtime (NÃO depende de Telescope
     *     estar ativo — sempre é feito, é idempotente e barato).
     *  2. Verifica se Telescope está habilitado (`telescopeIsEnabled`).
     *     Se NÃO estiver, early-return ANTES de `parent::boot()` —
     *     evita que o provider upstream registre rotas/views/middleware
     *     que ficariam orfãos (e.g. iriam falhar ao tentar gravar
     *     entries sem provider ativo).
     *  3. Chama `parent::boot()` (que faz authorization + gate).
     *  4. Registra o schedule de pruning.
     */
    #[Override]
    public function boot(): void
    {
        $this->registerTelescopeConnection();

        if (! $this->telescopeIsEnabled()) {
            return;
        }

        parent::boot();

        // Aplica ocultação de parâmetros sensíveis em ambientes não-local.
        // Deve ser chamado explicitamente: o parent::boot() upstream NÃO
        // invoca este método (verificado em vendor/laravel/telescope/src/
        // TelescopeApplicationServiceProvider.php — register() e boot()
        // estão vazios além de authorization()).
        $this->hideSensitiveRequestDetails();

        $this->registerSchedule();
    }

    /**
     * Register any application services.
     *
     * O `register()` é SEMPRE executado (independente do `boot()`)
     * porque a conexão SQLite é necessária para o Telescope funcionar
     * — sem ela, qualquer tentativa de gravar entry falha.
     *
     * O `hideSensitiveRequestDetails()` é movido do `register()` da
     * upstream para cá, mas só é efetivo quando Telescope está
     * habilitado (chamado via `boot()` se condição passar).
     */
    #[Override]
    public function register(): void
    {
        $this->registerTelescopeConnection();
    }

    /**
     * Registra a conexão `telescope` no DatabaseManager em runtime.
     *
     * Por que runtime? O M11-refactor do projeto zerou o array
     * `'connections' => []` em `config/database.php` — a app não tem
     * nenhuma conexão relacional compartilhada. Adicionar a conexão
     * `telescope` em `config/database.php` polui o config global.
     * Em vez disso, registramos aqui em todo `register()` e `boot()`,
     * garantindo que a conexão esteja disponível antes do primeiro uso
     * (incluindo o schedule `telescope:prune` rodando em cron).
     */
    private function registerTelescopeConnection(): void
    {
        config(['database.connections.telescope' => [
            'driver' => 'sqlite',
            'database' => database_path('telescope.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    /**
     * Verifica se o Telescope deve ser habilitado.
     *
     * Três camadas de defesa (qualquer `false` desliga):
     *  1. `config('telescope.enabled') === true` — master switch do .env.
     *  2. `App::environment('local')` — **APENAS** `local`, nunca
     *     `staging` ou `production` (decisão do usuário: staging pode
     *     rodar em ambiente compartilhado; telemetria rica só em local).
     *  3. (Camada extra no `gate()`: IP whitelist ao ACESSAR o painel.)
     */
    private function telescopeIsEnabled(): bool
    {
        return TelescopeHelper::isActive();
    }

    /**
     * Configure Telescope authorization — substitui a lógica padrão
     * (que verifica `app()->environment('local')`) por IP whitelist.
     *
     * O `parent::boot()` chama `authorization()` (que define Gate +
     * Telescope::auth). Substituímos `authorization()` para usar nosso
     * IP whitelist em vez do allowlist de email do upstream.
     */
    #[Override]
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function ($request) {
            return $this->isIpAllowed($request->ip());
        });
    }

    /**
     * Register the Telescope gate — IP whitelist.
     *
     * Autoriza acesso se o IP do request estiver na whitelist
     * (definida em {@see getAllowedIps()}). Bot é server-to-server
     * (sem `User` autenticado), então `$user` é tipicamente `null` —
     * o gate aceita esse caso.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user = null) {
            return $this->isIpAllowed(request()->ip());
        });
    }

    /**
     * Verifica se um IP está na whitelist de acesso ao Telescope.
     *
     * Itera sobre a lista de IPs/ranges (retornada por
     * {@see getAllowedIps()}) e delega ao {@see ipMatches()} para
     * verificar igualdade exata ou matching CIDR.
     */
    private function isIpAllowed(string $ip): bool
    {
        foreach ($this->getAllowedIps() as $allowed) {
            if ($this->ipMatches($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna a lista de IPs/ranges autorizados.
     *
     * Ordem de precedência:
     *  1. Env `TELESCOPE_ALLOWED_IPS` (CSV) — substitui defaults se setado.
     *  2. Defaults hardcoded — localhost + ranges Docker privados.
     *
     * Defaults:
     *  - `127.0.0.1`     — IPv4 loopback (dev local).
     *  - `::1`           — IPv6 loopback.
     *  - `172.16.0.0/12` — Docker default bridge network range.
     *  - `192.168.0.0/16` — redes privadas RFC 1918 comuns.
     *  - `10.0.0.0/8`    — redes privadas RFC 1918 (range maior).
     *
     * @return list<string>
     */
    private function getAllowedIps(): array
    {
        $env = env('TELESCOPE_ALLOWED_IPS', '');

        if ($env !== '') {
            return array_values(array_map('trim', explode(',', $env)));
        }

        return [
            '127.0.0.1',
            '::1',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '10.0.0.0/8',
        ];
    }

    /**
     * Verifica se um IP bate com um padrão (IP exato ou notação CIDR).
     *
     * Igualdade exata cobre `::1` (loopback IPv6) e `127.0.0.1`.
     * Para CIDR (somente IPv4), delega ao {@see cidrMatch()}.
     */
    private function ipMatches(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        if (str_contains($pattern, '/')) {
            return $this->cidrMatch($ip, $pattern);
        }

        return false;
    }

    /**
     * Faz matching CIDR IPv4 sem dependências externas.
     *
     * Implementação direta: converte ambos IPs para `long` (32-bit)
     * via `ip2long()`, aplica máscara de bits e compara.
     *
     * `ip2long()` retorna `false` para entradas inválidas — nesse
     * caso retornamos `false` (defensivo: nunca aceita IP malformado).
     */
    private function cidrMatch(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        // Sanitiza $bits no intervalo válido [0, 32]. Valores fora desse
        // intervalo (ex.: '33', '-1', ou sujeira do .env) são rejeitados
        // defensivamente em vez de produzirem máscara imprevisível.
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        // /0 = aceita tudo (mask 0). /32 = match exato (mask cheia).
        // O cálculo `-1 << (32 - $bits)` funciona corretamente em 64-bit
        // PHP porque os 32 bits significativos são preservados. Para /0
        // o resultado é `-1 << 32` que, em 64-bit, zera os 32 bits baixos
        // — exatamente o comportamento desejado.
        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     *
     * Em `local`, mantém visibilidade total (útil para debug). Em
     * outros ambientes, esconde `_token` (CSRF), `cookie`,
     * `x-csrf-token`, `x-xsrf-token`.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Registra o pruning automático do Telescope no Laravel Scheduler.
     *
     * O comando `telescope:prune --hours=168` remove entries com mais
     * de 7 dias. Roda diariamente (horário default = 00:00 UTC do
     * servidor). Retenção de 168h (7 dias) é o sweet spot: mantém
     * dados recentes para debug, sem crescimento ilimitado do SQLite.
     */
    private function registerSchedule(): void
    {
        Schedule::command('telescope:prune', ['--hours' => 168])->daily();
    }
}
