<?php

declare(strict_types=1);

namespace App\Services\DeepSeek;

use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\Parsing\AmountParser;
use App\Services\Parsing\DateNormalizer;
use App\Services\Parsing\TypeClassifier;
use Throwable;

/**
 * Camada de extração de transações a partir de texto livre (M3.2).
 *
 * Fluxo de {@see extract()}:
 *  1. Rejeita texto vazio (EMPTY_INPUT).
 *  2. Monta prompt do sistema + mensagem do usuário.
 *  3. Chama o {@see ChatCompleter} (DeepSeek via OpenAI-compatível).
 *  4. Decodifica o JSON; JSON inválido → INVALID_JSON.
 *  5. Normaliza via pipeline (AmountParser, DateNormalizer, TypeClassifier)
 *     e monta o {@see TransactionData}.
 *  6. Valida saída (description ausente → MISSING_REQUIRED_FIELDS;
 *     amount presente porém <= 0 → INVALID_AMOUNT).
 *
 * Escopo M3: campos "pedíveis" (valor/tipo) ausentes NÃO lançam exceção —
 * retornam null no DTO, e o tratamento conversacional (pedir valor, perguntar
 * tipo) é responsabilidade de M5. Aqui só há exceção para falhas estruturais.
 */
final class DeepSeekService
{
    private ?string $systemPrompt = null;

    public function __construct(
        private readonly ChatCompleter $completer,
        private readonly AmountParser $amountParser,
        private readonly DateNormalizer $dateNormalizer,
        private readonly TypeClassifier $typeClassifier,
    ) {}

    /**
     * @throws ExtractionException Quando a extração é estruturalmente inviável.
     */
    public function extract(string $text): TransactionData
    {
        if (trim($text) === '') {
            throw new ExtractionException(
                reason: ExtractionException::EMPTY_INPUT,
                message: 'Texto de entrada vazio.',
            );
        }

        $content = $this->callCompleter($text);

        $data = $this->decodeJson($content);

        // Pipeline de normalização defensiva sobre a saída do LLM.
        $data['amount'] = $this->amountParser->parse($data['amount'] ?? null);
        $data['date'] = $this->dateNormalizer->normalize($data['date'] ?? null);
        $data['type'] = $this->typeClassifier->classify($data['type'] ?? null, $text);

        $dto = TransactionData::fromArray($data);

        $this->validate($dto);

        return $dto;
    }

    /**
     * Invoca o completer encapsulando qualquer erro bruto. Implementações
     * bem-comportadas (como {@see OpenAIChatCompleter}) já devolvem
     * ExtractionException(API_ERROR); aqui garantimos a invariante mesmo
     * para implementações que vazem Throwables crus.
     *
     * @throws ExtractionException
     */
    private function callCompleter(string $text): string
    {
        try {
            return $this->completer->complete(
                systemPrompt: $this->systemPrompt(),
                userMessages: [['role' => 'user', 'content' => $text]],
            );
        } catch (ExtractionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Falha ao chamar o completer de chat.',
                previous: $e,
            );
        }
    }

    /**
     * Decodifica o conteúdo retornado pelo LLM como array associativo.
     *
     * @return array<string, mixed>
     *
     * @throws ExtractionException
     */
    private function decodeJson(string $content): array
    {
        if (trim($content) === '') {
            throw new ExtractionException(
                reason: ExtractionException::INVALID_JSON,
                message: 'Resposta do DeepSeek vazia.',
            );
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new ExtractionException(
                reason: ExtractionException::INVALID_JSON,
                message: 'Resposta do DeepSeek não é um JSON válido.',
            );
        }

        return $data;
    }

    /**
     * Valida campos obrigatórios e invariantes de negócio (spec §9 / M3.8).
     *
     * Importante: amount null é ACEITO (campo pedível → M5); só rejeitamos
     * amount presente porém não-positivo.
     *
     * @throws ExtractionException
     */
    private function validate(TransactionData $dto): void
    {
        if ($dto->description === null || mb_strlen(trim($dto->description)) < 2) {
            throw new ExtractionException(
                reason: ExtractionException::MISSING_REQUIRED_FIELDS,
                message: 'description ausente ou muito curta.',
            );
        }

        if ($dto->amount !== null && $dto->amount <= 0) {
            throw new ExtractionException(
                reason: ExtractionException::INVALID_AMOUNT,
                message: 'amount deve ser positivo.',
            );
        }
    }

    /**
     * Carrega (uma única vez) o prompt do sistema de resources/prompts/,
     * prefixando um cabeçalho temporal com a data de hoje.
     *
     * O cabeçalho temporal é essencial para que o LLM interprete corretamente
     * expressões relativas ("hoje", "ontem", "anteontem") sem alucinar datas.
     * É defesa em profundidade: o DateNormalizer também trata esses literais,
     * mas informar o LLM evita erros na própria extração.
     */
    private function systemPrompt(): string
    {
        return $this->systemPrompt ??= (function (): string {
            $now = now();
            $todayPtBr = $now->format('d/m/Y');
            $todayIso = $now->toDateString();

            $header = <<<HEADER
INFORMAÇÃO TEMPORAL: Hoje é {$todayPtBr} (formato ISO: {$todayIso}).
Use esta data como referência para interpretar expressões temporais relativas ("hoje" = {$todayIso}, "ontem" = dia anterior, "anteontem" = dois dias antes).
HEADER;

            // @phpstan-ignore-next-line (require de arquivo que retorna string)
            $base = (string) require resource_path('prompts/text-extraction.php');

            return $header."\n\n".$base;
        })();
    }
}
