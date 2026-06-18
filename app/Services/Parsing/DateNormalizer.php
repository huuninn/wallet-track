<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use Carbon\Carbon;
use Closure;

/**
 * Normalizador defensivo de data (M3.6).
 *
 * Converte a saída eventualmente inconsistente do LLM para o formato ISO
 * YYYY-MM-DD, interpretando expressões relativas e formatos brasileiros:
 *
 *  - null / ""            → "hoje" (default da spec §9).
 *  - "hoje"               → data atual.
 *  - "ontem"              → dia anterior.
 *  - "anteontem"          → dois dias antes.
 *  - "YYYY-MM-DD"         → mantido (validado).
 *  - "DD/MM/YYYY"         → convertido.
 *  - "DD-MM-YYYY"         → convertido.
 *
 * Decisão M3 — datas futuras: a spec §9 diz que data futura "não pode ser
 * usada sem confirmação". Como a confirmação conversacional é M5, aqui
 * normalizamos data futura para HOJE (fallback seguro) e emitimos um log
 * warning via callback opcional. O chamador M5 poderá refinar este
 * comportamento quando existir o fluxo de confirmação.
 */
final class DateNormalizer
{
    /**
     * @param  Closure(string $message, mixed ...$context): void|null  $onWarning
     *                                                                             Callback de log opcional — injetado pelo serviço para avisar sobre
     *                                                                             data futura normalizada para hoje. Default: silencioso.
     */
    public function __construct(
        private readonly ?Closure $onWarning = null,
    ) {}

    /**
     * @return string|null Data ISO YYYY-MM-DD, ou null se impossível de extrair.
     */
    public function normalize(mixed $value): ?string
    {
        $today = Carbon::today();

        if ($value === null) {
            return $today->toDateString();
        }

        if (! is_string($value)) {
            return $today->toDateString();
        }

        $value = trim($value);
        if ($value === '') {
            return $today->toDateString();
        }

        $normalized = $this->resolveRelativeOrAbsolute($value, $today);

        if ($normalized === null) {
            // Formato irreconhecível → fallback para hoje (defensivo) + warning.
            $this->warn('Data com formato irreconhecível normalizada para hoje.', [
                'original' => $value,
            ]);

            return $today->toDateString();
        }

        // Rejeita datas futuras: normaliza para hoje e avisa (M3).
        if (Carbon::parse($normalized)->isAfter($today)) {
            $this->warn('Data futura normalizada para hoje (confirmação é M5).', [
                'original' => $value,
                'parsed' => $normalized,
            ]);

            return $today->toDateString();
        }

        return $normalized;
    }

    private function resolveRelativeOrAbsolute(string $value, Carbon $today): ?string
    {
        $lower = mb_strtolower($value);

        return match (true) {
            $lower === 'hoje' => $today->toDateString(),
            $lower === 'ontem' => $today->copy()->subDay()->toDateString(),
            $lower === 'anteontem' => $today->copy()->subDays(2)->toDateString(),
            default => $this->parseAbsolute($value),
        };
    }

    private function parseAbsolute(string $value): ?string
    {
        // ISO YYYY-MM-DD.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $this->validDate($value) ? $value : null;
        }

        // DD/MM/YYYY ou DD-MM-YYYY.
        if (preg_match('#^(\d{2})[/-](\d{2})[/-](\d{4})$#', $value, $m)) {
            $iso = "{$m[3]}-{$m[2]}-{$m[1]}";

            return $this->validDate($iso) ? $iso : null;
        }

        return null;
    }

    /**
     * Valida que a data ISO é um calendário real (ex.: rejeita 2025-02-30).
     * Usa checkdate() em vez de Carbon::parse(), que faria overflow para
     * 2025-03-02 silenciosamente.
     */
    private function validDate(string $iso): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function warn(string $message, array $context = []): void
    {
        if ($this->onWarning !== null) {
            ($this->onWarning)($message, $context);
        }
    }
}
