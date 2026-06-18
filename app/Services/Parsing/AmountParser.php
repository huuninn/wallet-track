<?php

declare(strict_types=1);

namespace App\Services\Parsing;

/**
 * Parser defensivo de valor monetário (M3.5).
 *
 * Normaliza a saída eventualmente inconsistente do LLM (ou de entrada do
 * usuário) para um float positivo. Aceita formatos brasileiros e ambíguos:
 *
 *  - Número JSON (int/float)              → abs direto.
 *  - "1.234,56" / "R$ 1.234,56"           → 1234.56 (vírgula = decimal).
 *  - "45,90" / "R$5,50"                   → 45.90 / 5.50.
 *  - "1.234.567,89"                       → 1234567.89 (múltiplos pontos = milhar).
 *  - "45.90"                              → 45.90 (ponto = decimal; 2 casas).
 *  - "1.234" (3 casas após ponto, único)  → 1234 (interpretado como milhar).
 *  - "2000"                               → 2000.00.
 *
 * Regra de desambiguação do ponto (sem vírgula): se houver 3 dígitos após
 * o último (e único) ponto, assume-se separador de milhar; caso contrário,
 * decimal. Isto cobre os casos de CT-006/CT-006b.
 *
 * Retorna null quando não há número parseável (ex.: "", "abc", null).
 * Retorna SEMPRE o módulo (abs) — valor é positivo por especificação §9.
 */
final class AmountParser
{
    /**
     * @return float|null Float positivo, ou null se não parseável.
     */
    public function parse(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        // Números vindos do JSON já estão tipados.
        if (is_int($value) || is_float($value)) {
            return abs((float) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Extrai o primeiro token numérico (com sinal, dígitos, pontos e vírgulas).
        if (! preg_match('/(-?)\s*[\d][\d.,]*/', $value, $matches)) {
            return null;
        }

        // Remove pontuação final de frase (ponto, vírgula, ponto-e-vírgula,
        // dois-pontos, exclamação, interrogação) que o regex captura
        // incidentalmente — ex.: "45.90." não deve virar 4590 (milhar).
        $token = rtrim($matches[0], '.,;:!?');
        if ($token === '' || ! preg_match('/\d/', $token)) {
            return null;
        }

        return $this->normalizeToken($token);
    }

    private function normalizeToken(string $token): ?float
    {
        $hasComma = str_contains($token, ',');
        $hasDot = str_contains($token, '.');

        if ($hasComma) {
            // Vírgula define o decimal: remove todos os pontos (milhar) e
            // troca a vírgula por ponto. "1.234,56" → "1234.56".
            $normalized = str_replace('.', '', $token);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasDot) {
            $dotCount = substr_count($token, '.');
            $lastDotDigits = strlen(substr($token, strrpos($token, '.') + 1));

            if ($dotCount > 1 || $lastDotDigits === 3) {
                // Múltiplos pontos OU ponto único com 3 casas → milhar.
                $normalized = str_replace('.', '', $token);
            } else {
                // Ponto único com 1, 2 ou >3 casas → decimal.
                $normalized = $token;
            }
        } else {
            // Sem separador → inteiro.
            $normalized = $token;
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return abs((float) $normalized);
    }
}
