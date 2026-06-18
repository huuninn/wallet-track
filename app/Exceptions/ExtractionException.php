<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Dto\TransactionData;
use RuntimeException;
use Throwable;

/**
 * Exceção lançada pela camada de extração (DeepSeek/Action) quando algo
 * impede a produção de um {@see TransactionData} utilizável.
 *
 * O `reason` (constantes abaixo) permite que o chamador (M5 — fluxo
 * conversacional) decida o fallback adequado: pedir valor faltante,
 * perguntar o tipo ambíguo, ou sugerir a entrada manual via `/nova`
 * (spec §10: "DeepSeek/Gemini indisponíveis → fallback manual").
 *
 * Nota de escopo M3: a distinção entre "campo faltante pedível" (valor/tipo)
 * e "falha estrutural" (API/JSON) é feita via reason. Campos pedíveis NÃO
 * lançam esta exceção — retornam null no DTO; o tratamento conversacional
 * é M5.
 */
class ExtractionException extends RuntimeException
{
    /** JSON retornado pelo LLM não decodificou para um objeto válido. */
    public const string INVALID_JSON = 'invalid_json';

    /** Campos obrigatórios ausentes (ex.: description vazia). */
    public const string MISSING_REQUIRED_FIELDS = 'missing_required_fields';

    /** amount presente no JSON mas inválido (<= 0 após normalização). */
    public const string INVALID_AMOUNT = 'invalid_amount';

    /** Falha de comunicação/timeout com a API do DeepSeek. */
    public const string API_ERROR = 'api_error';

    /** Texto de entrada vazio ou apenas whitespace. */
    public const string EMPTY_INPUT = 'empty_input';

    /** Input presente mas em formato não suportado (ex.: mimeType inválido). */
    public const string INVALID_INPUT = 'invalid_input';

    /**
     * A imagem não contém uma transação clara (M4.6).
     *
     * Sinaliza que o Gemini analisou a imagem mas os campos críticos
     * (description e amount) vieram null — ex.: foto de cachorro (CT-009),
     * imagem totalmente borrada (CT-008). Distinto de API_ERROR (a chamada
     * teve sucesso; o conteúdo simplesmente não é uma nota fiscal).
     */
    public const string NOT_A_TRANSACTION = 'not_a_transaction';

    /**
     * @param  string  $reason  Uma das constantes self::*.
     * @param  string  $message  Mensagem curta (segura p/ log interno).
     * @param  Throwable|null  $previous  Exceção original — USO INTERNO APENAS:
     *                                    NUNCA exponha getPrevious()->getMessage() ao usuário final (pode
     *                                    conter detalhes da API). Reserve para stack traces em logs.
     */
    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : "Falha na extração: {$reason}",
            previous: $previous,
        );
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
