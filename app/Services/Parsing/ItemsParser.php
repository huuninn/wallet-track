<?php

declare(strict_types=1);

namespace App\Services\Parsing;

use Illuminate\Support\Facades\Log;

/**
 * Parser da sintaxe compacta de items do wizard/exibição.
 *
 * Converte texto multiline do usuário em lista de items normalizados.
 * Stateless e puro (sem I/O) — trivialmente testável.
 *
 * Sintaxe aceita POR LINHA (Decisão PC2=a):
 *
 *   Arroz 5kg x2 32.90   → name="Arroz 5kg", qty=2.0, unitPrice=32.90, subtotal=65.80
 *   Feijão               → name="Feijão",    qty=null, unitPrice=null,  subtotal=null
 *   Detergente x1 4.50   → name="Detergente", qty=1.0, unitPrice=4.50,  subtotal=4.50
 *   Coca 2L              → name="Coca 2L",   qty=null, unitPrice=null,  subtotal=null
 *   Pizza x-tudo         → name="Pizza x-tudo" (x-tudo NÃO é qty — não é seguido de dígito)
 *
 * Algoritmo:
 *  1. Split por "\n" (ou "\r\n", "\r");
 *  2. Para cada linha: trim; ignorar vazias;
 *  3. Regex por linha extrai name (até " x<digitos>"), qty opcional, unitPrice opcional;
 *  4. Se qty e unitPrice presentes: subtotal = round(qty * unitPrice, 2);
 *  5. Caso contrário: subtotal = null;
 *  6. Reindexa com array_values.
 *
 * Linhas que não casam (ex.: só whitespace, ou só número) são descartadas
 * com Log::warning (observabilidade — NÃO lança exceção).
 *
 * Performance: O(N) onde N = número de linhas.
 */
final class ItemsParser
{
    /**
     * Regex por linha. Flags:
     *  - `u` (UTF-8): nomes PT-BR com acentos.
     *  - grupos nomeados para legibilidade.
     *
     * Decomposição:
     *   ^(?<name>.+?)                     — nome: lazy, captura o mínimo
     *   (?:\s+x(?<qty>\d+(?:[.,]\d+)?))?   — " xN" opcional: espaço + x + int/decimal
     *                                        (não casa se x é seguido de não-dígito → "x-tudo" fica no nome)
     *   (?:\s+(?<price>\d+(?:[.,]\d{1,2})))?  — preço opcional: espaço + número decimal 1-2 casas
     *   \s*$                               — fim (com whitespace residual tolerado)
     *
     * O lazy `.+?` no nome garante que a primeira ocorrência de " x<digitos>"
     * é consumida como qty, preservando o resto no nome se houver ambiguidade.
     */
    private const string LINE_REGEX = '/^(?<name>.+?)(?:\s+x(?<qty>\d+(?:[.,]\d+)?))?(?:\s+(?<price>\d+(?:[.,]\d{1,2})))?\s*$/u';

    /**
     * Converte a string multiline de items em lista de maps normalizados.
     *
     * @param  string  $raw      Texto bruto do usuário (multiline).
     * @param  int     $maxItems Limite de segurança contra listas gigantes (default 200).
     * @return list<array{name:string,qty:float|null,unitPrice:float|null,subtotal:float|null}>
     */
    public function parse(string $raw, int $maxItems = 200): array
    {
        // Normaliza quebras de linha (CRLF/CR/LF → LF) e divide.
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        $items = [];
        foreach ($lines as $currentIndex => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // W5: limite de segurança contra listas gigantes.
            if (count($items) >= $maxItems) {
                Log::warning('ItemsParser: limite de items atingido, truncando', [
                    'max' => $maxItems,
                    'remaining_lines' => count($lines) - $currentIndex,
                ]);
                break;
            }

            if (! preg_match(self::LINE_REGEX, $line, $m)) {
                Log::warning('ItemsParser: linha não casou (descartada)', ['line' => $line]);
                continue;
            }

            $name = trim($m['name']);
            if ($name === '') {
                continue;
            }

            $qty = isset($m['qty']) && $m['qty'] !== '' ? $this->parseNumber($m['qty']) : null;
            // Clamp de qty negativo é responsabilidade do DTO (normalizeItems)
            // para dados vindos do LLM. Aqui o regex só captura \d+, então
            // qty nunca será negativo — remover o clamp evita código morto (W-D).
            $unitPrice = isset($m['price']) && $m['price'] !== '' ? $this->parseNumber($m['price']) : null;

            // Subtotal calculado apenas quando ambos qty e unitPrice estão presentes.
            // Invariante: não calcula se falta algum — fica null.
            $subtotal = ($qty !== null && $unitPrice !== null) ? round($qty * $unitPrice, 2) : null;

            $items[] = [
                'name' => $name,
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'subtotal' => $subtotal,
            ];
        }

        return $items;
    }

    /**
     * Converte string numérica PT-BR/US para float.
     *
     * Heurística:
     *  - "32,90" → 32.90 (vírgula decimal PT-BR).
     *  - "32.90" → 32.90 (ponto decimal US).
     *  - "1.234,56" → 1234.56 (se há vírgula E ponto, ponto é milhar; vírgula é decimal).
     *  - "2.5" → 2.5 (ponto decimal com 1 casa).
     *
     * @param  string  $raw  String numérica bruta.
     * @return float  Valor convertido.
     */
    private function parseNumber(string $raw): float
    {
        // Heurística: se há vírgula E ponto, ponto é milhar → remove; vírgula vira ponto.
        // Se só vírgula, vírgula é decimal → vira ponto.
        // Senão, mantém (ponto decimal US padrão).
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            // Ambos presentes: ponto é separador de milhar, vírgula é decimal.
            $raw = str_replace('.', '', $raw);   // remove milhar
            $raw = str_replace(',', '.', $raw);  // decimal
        } elseif (str_contains($raw, ',')) {
            // Só vírgula: vírgula é decimal PT-BR.
            $raw = str_replace(',', '.', $raw);
        }
        // Senão: ponto decimal US ou inteiro puro — PHP (float) já trata.

        return (float) $raw;
    }
}
