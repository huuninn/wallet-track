<?php

declare(strict_types=1);

namespace App\Services\Parsing;

/**
 * Classificador defensivo de tipo de transação (M3.7).
 *
 * Quando o LLM já devolve "expense"/"income", apenas valida/normaliza. Quando
 * devolve null (ambíguo), aplica heurística de palavras-chave sobre o texto
 * original (hint). Se mesmo assim for ambíguo, retorna null — o tratamento
 * conversacional ("despesa ou receita?") fica para M5 (CT-004).
 *
 * As listas de palavras evitam termos ambíguos como "freelance" (que pode
 * ser tanto receita quanto despesa, conforme CT-004).
 */
final class TypeClassifier
{
    /** Palavras-chave fortes de DESPESA (radicais comparados via mb_stripos). */
    private const array EXPENSE_KEYWORDS = [
        'paguei', 'gastei', 'gasto', 'custo', 'custou', 'comprei', 'compra',
        'comprado', 'pagamento', 'despesa', 'aluguel', 'assinatura', 'conta de',
        'gaste',
    ];

    /** Palavras-chave fortes de RECEITA. */
    private const array INCOME_KEYWORDS = [
        'recebi', 'ganhei', 'salário', 'salario', 'venda', 'vendi',
        'depósito', 'deposito', 'transferência', 'transferencia', 'pró-labore',
        'pro-labore', 'proventos', 'rendimento', 'pagaram',
    ];

    /**
     * @param  mixed  $value  Tipo retornado pelo LLM ("expense"/"income"/null).
     * @param  string|null  $hintText  Texto original para heurística quando value é null.
     * @return string|null "expense", "income" ou null (ambíguo).
     */
    public function classify(mixed $value, ?string $hintText = null): ?string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['expense', 'income'], true)) {
                return $normalized;
            }
        }

        // Sem hint → não há como desambiguar.
        if ($hintText === null || trim($hintText) === '') {
            return null;
        }

        return $this->inferFromText($hintText);
    }

    private function inferFromText(string $text): ?string
    {
        $expense = $this->containsAny($text, self::EXPENSE_KEYWORDS);
        $income = $this->containsAny($text, self::INCOME_KEYWORDS);

        // Se ambas as heurísticas casam (texto confuso), permanece ambíguo.
        if ($expense && $income) {
            return null;
        }

        if ($expense) {
            return 'expense';
        }

        if ($income) {
            return 'income';
        }

        return null;
    }

    /**
     * Verifica se o texto contém alguma das palavras (case/acento-insensível
     * no radical — usamos mb_stripos, que é case-insensível; acentos são
     * cobertos incluindo variantes sem acento nas listas).
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($text, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }
}
