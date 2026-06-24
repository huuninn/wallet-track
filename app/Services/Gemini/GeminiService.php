<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Dto\TransactionData;
use App\Exceptions\ExtractionException;
use App\Services\DeepSeek\DeepSeekService;
use App\Services\Parsing\AmountParser;
use App\Services\Parsing\DateNormalizer;
use App\Services\Parsing\TypeClassifier;
use App\Support\LabelFormatter;
use Throwable;

/**
 * Camada de extração de transações a partir de imagens de notas fiscais (M4).
 *
 * Espelho multimodal do {@see DeepSeekService}: em vez
 * de texto livre, recebe uma imagem (base64 + mimeType) e devolve um
 * {@see TransactionData} validado.
 *
 * Fluxo de {@see extractFromImage()}:
 *  1. Rejeita input vazio (EMPTY_INPUT) ou mimeType inválido (INVALID_INPUT).
 *  2. Chama o {@see ImageCompleter} (Gemini multimodal → JSON estruturado).
 *  3. Decodifica o JSON; JSON inválido → INVALID_JSON.
 *  4. Normaliza via pipeline (AmountParser, DateNormalizer, TypeClassifier)
 *     e monta o {@see TransactionData}.
 *  5. Fallback M4.6: se description === null E amount === null → a imagem
 *     não é uma transação clara (CT-008/CT-009) → NOT_A_TRANSACTION.
 *  6. Valida saída (description ausente → MISSING_REQUIRED_FIELDS;
 *     amount presente porém <= 0 → INVALID_AMOUNT).
 *
 * Escopo M4: a camada conversacional (feedback de progresso, fallback /nova)
 * é M5. Aqui lançamos exceções estruturadas; o chamador decide o que fazer.
 */
final class GeminiService
{
    /**
     * MIME types de imagem suportados pelo enum Gemini\Data\MimeType.
     * GIF não é suportado pelo pacote (sem case no enum) — omitido.
     */
    private const array SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        private readonly ImageCompleter $completer,
        private readonly AmountParser $amountParser,
        private readonly DateNormalizer $dateNormalizer,
        private readonly TypeClassifier $typeClassifier,
    ) {}

    /**
     * @throws ExtractionException Quando a extração é estruturalmente inviável.
     */
    public function extractFromImage(string $base64Image, string $mimeType, array $labelCatalog = []): TransactionData
    {
        $this->validateInput($base64Image, $mimeType);

        $content = $this->callCompleter($base64Image, $mimeType, $labelCatalog);

        $data = $this->decodeJson($content);

        // Pipeline de normalização defensiva sobre a saída do LLM (mesmo
        // pipeline do M3 — reutilizado, não recriado). O hint de texto para
        // o TypeClassifier é a própria descrição extraída (quando há), pois
        // não há texto original do usuário em OCR — apenas a imagem. Quando
        // description é null, o TypeClassifier opera apenas sobre o campo
        // type cru — classificação menos precisa, mas ainda funcional.
        $hintText = is_string($data['description'] ?? null) ? (string) $data['description'] : null;
        $data['amount'] = $this->amountParser->parse($data['amount'] ?? null);
        $data['date'] = $this->dateNormalizer->normalize($data['date'] ?? null);
        $data['type'] = $this->typeClassifier->classify($data['type'] ?? null, $hintText);

        $dto = TransactionData::fromArray($data);

        // Defesa em profundidade: o prompt pede capitalização, mas o LLM pode ignorar.
        $capitalizedLabels = array_map(
            fn (string $l): string => LabelFormatter::format($l),
            $dto->labels,
        );

        // Deduplica por fold — ex.: o LLM pode devolver ["Almoço", "almoco"].
        $deduped = LabelFormatter::deduplicate($capitalizedLabels);
        $dto = $dto->withLabels($deduped);

        $this->detectNotATransaction($dto);

        $this->validate($dto);

        return $dto;
    }

    /**
     * Valida input: base64 não-vazio (EMPTY_INPUT) e mimeType de imagem
     * suportado (INVALID_INPUT quando presente mas em formato errado).
     *
     * @throws ExtractionException
     */
    private function validateInput(string $base64Image, string $mimeType): void
    {
        if (trim($base64Image) === '') {
            throw new ExtractionException(
                reason: ExtractionException::EMPTY_INPUT,
                message: 'Imagem base64 de entrada vazia.',
            );
        }

        if (! in_array(strtolower(trim($mimeType)), self::SUPPORTED_MIME_TYPES, true)) {
            throw new ExtractionException(
                reason: ExtractionException::INVALID_INPUT,
                message: "MIME type de imagem não suportado: {$mimeType}.",
            );
        }
    }

    /**
     * Invoca o completer encapsulando qualquer erro bruto.
     *
     * @throws ExtractionException
     */
    private function callCompleter(string $base64Image, string $mimeType, array $labelCatalog = []): string
    {
        try {
            return $this->completer->complete(
                systemPrompt: $this->systemPrompt($labelCatalog),
                base64Image: $base64Image,
                mimeType: $mimeType,
            );
        } catch (ExtractionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ExtractionException(
                reason: ExtractionException::API_ERROR,
                message: 'Falha ao chamar o completer de imagem.',
                previous: $e,
            );
        }
    }

    /**
     * Decodifica o conteúdo retornado pelo Gemini como array associativo.
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
                message: 'Resposta do Gemini vazia.',
            );
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new ExtractionException(
                reason: ExtractionException::INVALID_JSON,
                message: 'Resposta do Gemini não é um JSON válido.',
            );
        }

        return $data;
    }

    /**
     * Fallback M4.6: se ambos os campos críticos (description E amount) são
     * null, a imagem não representa uma transação clara — ex.: foto de
     * cachorro (CT-009) ou imagem borrada sem dados legíveis (CT-008).
     *
     * Decisão de design: lançar ExtractionException(NOT_A_TRANSACTION) em
     * vez de retornar o DTO com nulls, pois o contrato fica mais claro — o
     * chamador (M5) captura esta reason especificamente e oferece o canal de
     * texto/imagem alternativa. Distinto de MISSING_REQUIRED_FIELDS (onde a
     * descrição existia mas era curta, ou amount era inválido).
     *
     * @throws ExtractionException
     */
    private function detectNotATransaction(TransactionData $dto): void
    {
        if ($dto->description === null && $dto->amount === null) {
            throw new ExtractionException(
                reason: ExtractionException::NOT_A_TRANSACTION,
                message: 'A imagem não contém uma transação clara.',
            );
        }
    }

    /**
     * Valida campos obrigatórios e invariantes de negócio (spec §9).
     *
     * Importante: amount null com description presente é ACEITO (campo
     * pedível → M5); só rejeitamos amount presente porém não-positivo.
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
     * Carrega o prompt do sistema de resources/prompts/ prefixando um
     * cabeçalho temporal com a data de hoje e interpolando o catálogo de
     * labels do usuário no placeholder `{{LABEL_CATALOG}}`.
     *
     * Mesma abordagem do DeepSeekService: o cabeçalho temporal é essencial
     * para que o Gemini interprete datas relativas e use "hoje" como default
     * quando a nota não tem data legível. O catálogo é injetado via `strtr()`.
     *
     * @param  list<string>  $labelCatalog  Top-N labels do usuário (display names).
     */
    private function systemPrompt(array $labelCatalog): string
    {
        $now = now();
        $todayPtBr = $now->format('d/m/Y');
        $todayIso = $now->toDateString();

        $header = <<<HEADER
INFORMAÇÃO TEMPORAL: Hoje é {$todayPtBr} (formato ISO: {$todayIso}).
Use esta data como referência para interpretar datas impressas na nota e como default quando a nota não tiver data legível ("hoje" = {$todayIso}).
HEADER;

        // @phpstan-ignore-next-line (require de arquivo que retorna string)
        $base = (string) require resource_path('prompts/image-ocr.php');

        $catalogStr = $labelCatalog === []
            ? '(o usuário ainda não tem labels anteriores)'
            : implode(', ', $labelCatalog);

        $body = strtr($base, ['{{LABEL_CATALOG}}' => $catalogStr]);

        return $header."\n\n".$body;
    }
}
