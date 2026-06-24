<?php

declare(strict_types=1);

namespace App\Actions;

use App\Dto\TransactionData;
use App\Services\DeepSeek\ChatCompleter;
use App\Support\LabelFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sugestão de labels via LLM dedicado (M2).
 *
 * Diferente da heurística PHP ({@see SuggestLabels}, deprecated), esta action
 * delega a tarefa de selecionar labels ao próprio DeepSeek, usando um prompt
 * especializado ({@see resource_path('prompts/label-suggestion.php')}) que
 * recebe o contexto completo da transação e o catálogo de labels do usuário.
 *
 * Fluxo de {@see suggest()}:
 *  1. Se description e category são vazias, retorna `[]` sem chamar o LLM.
 *  2. Monta o prompt do sistema (com catálogo e max_labels) e a mensagem do
 *     usuário (com os campos da transação).
 *  3. Chama o {@see ChatCompleter} com temperatura baixa (0.3, configurável).
 *  4. Decodifica o JSON `{"labels": [...]}`, aplica {@see LabelFormatter::format()}
 *     em cada label, deduplica via {@see TextNormalizer::fold()} e trunca
 *     para o máximo configurado.
 *  5. Se qualquer passo falhar (LLM offline, JSON inválido, etc.), loga o
 *     warning e retorna `[]` — NUNCA lança exceção.
 *
 * Design defensivo: a action é "best-effort". Labels ruins ou ausentes não
 * quebram o fluxo de registro da transação; o usuário sempre pode editar
 * depois. Por isso, falhas são silenciosas (com log).
 *
 * @implements SuggestsLabels
 */
final class SuggestLabelsLLM implements SuggestsLabels
{
    public function __construct(
        private readonly ChatCompleter $completer,
    ) {}

    /**
     * Sugere labels para uma transação via LLM.
     *
     * @param  TransactionData  $dto  Draft parcial (com description/category/amount/type).
     * @param  list<string>  $labelCatalog  Top-N labels do usuário (display names).
     * @return list<string> Labels sugeridas (≤ max, capitalizadas). Pode ser vazia.
     */
    public function suggest(TransactionData $dto, array $labelCatalog = []): array
    {
        // Se não há descrição nem categoria, não há o que sugerir.
        $desc = $dto->description ?? '';
        $cat = $dto->category ?? '';
        if (trim($desc) === '' && trim($cat) === '') {
            return [];
        }

        $max = (int) config('labels.max_labels', 3);
        $temperature = (float) config('labels.suggestion_temperature', 0.3);

        $systemPrompt = $this->buildSystemPrompt($labelCatalog, $max);
        $userPrompt = $this->buildUserPrompt($dto);

        try {
            $content = $this->completer->complete(
                systemPrompt: $systemPrompt,
                userMessages: [['role' => 'user', 'content' => $userPrompt]],
                options: ['temperature' => $temperature],
            );
        } catch (Throwable $e) {
            Log::warning('SuggestLabelsLLM: chamada LLM falhou', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->parseAndNormalize($content, $max);
    }

    /**
     * Monta o prompt do sistema carregando o arquivo de prompt e interpolando
     * os placeholders `{{MAX_LABELS}}` e `{{LABEL_CATALOG}}`.
     *
     * @param  list<string>  $labelCatalog
     */
    private function buildSystemPrompt(array $labelCatalog, int $max): string
    {
        // @phpstan-ignore-next-line (require de arquivo que retorna string)
        $base = (string) require resource_path('prompts/label-suggestion.php');

        $catalogStr = $labelCatalog === []
            ? '(o usuário ainda não tem labels anteriores)'
            : implode(', ', $labelCatalog);

        return strtr($base, [
            '{{MAX_LABELS}}' => (string) $max,
            '{{LABEL_CATALOG}}' => $catalogStr,
        ]);
    }

    /**
     * Monta a mensagem do usuário com o contexto da transação.
     *
     * Formato: texto simples com os campos disponíveis, para o LLM interpretar.
     */
    private function buildUserPrompt(TransactionData $dto): string
    {
        $parts = [];

        if ($dto->description !== null && trim($dto->description) !== '') {
            $parts[] = 'Descrição: '.$dto->description;
        }

        if ($dto->category !== null && trim($dto->category) !== '') {
            $parts[] = 'Categoria: '.$dto->category;
        }

        if ($dto->type !== null) {
            $typeLabel = $dto->type === 'income' ? 'Receita' : 'Despesa';
            $parts[] = 'Tipo: '.$typeLabel;
        }

        if ($dto->amount !== null) {
            $parts[] = 'Valor: R$ '.number_format($dto->amount, 2, ',', '.');
        }

        if ($parts === []) {
            return 'Transação sem contexto adicional.';
        }

        return implode("\n", $parts);
    }

    /**
     * Decodifica o JSON de resposta, formata e deduplica as labels.
     *
     * Estratégia defensiva:
     *  1. `json_decode()` — se falhar, retorna `[]`.
     *  2. Extrai o array `labels` — se não for array, retorna `[]`.
     *  3. Aplica {@see LabelFormatter::format()} em cada label (P1 + P7).
     *  4. Deduplica via {@see TextNormalizer::fold()} (case + accent insensitive),
     *     mantendo a primeira ocorrência.
     *  5. Trunca para `$max` labels.
     *
     * @param  string  $content  Resposta bruta do LLM (esperado JSON).
     * @param  int  $max  Teto de labels.
     * @return list<string>
     */
    private function parseAndNormalize(string $content, int $max): array
    {
        // Tolerância a markdown fences: o LLM pode devolver JSON cercado de
        // ```json ... ``` — exemplo comum em modelos que não foram
        // fine-tuned para "raw JSON only". O strip é feito ANTES do decode
        // para que json_decode() encontre apenas o conteúdo JSON puro.
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            // Remove a linha de abertura (```json ou ```).
            $content = preg_replace('/^```(?:json)?\s*\n?/u', '', $content);
            // Remove a linha de fechamento (``` no final).
            $content = preg_replace('/\n?```\s*$/u', '', $content);
            $content = trim($content);
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            Log::warning('SuggestLabelsLLM: JSON inválido na resposta do LLM', [
                'content' => mb_substr($content, 0, 200),
            ]);

            return [];
        }

        $labels = $data['labels'] ?? [];

        if (! is_array($labels)) {
            return [];
        }

        // Formata cada label (P1 + P7).
        $formatted = [];
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '') {
                continue;
            }
            $formatted[] = LabelFormatter::format($label);
        }

        // Deduplica via TextNormalizer::fold() — case + accent insensitive.
        $deduped = LabelFormatter::deduplicate($formatted);

        // Trunca para o máximo.
        if (count($deduped) > $max) {
            $deduped = array_slice($deduped, 0, $max);
        }

        return array_values($deduped);
    }
}
