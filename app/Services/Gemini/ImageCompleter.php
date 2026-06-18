<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Exceptions\ExtractionException;

/**
 * Abstração sobre o cliente multimodal do Gemini (imagem → JSON estruturado).
 *
 * Isolar esta chamada por trás de uma interface permite que a
 * {@see GeminiService} seja testada com um FakeImageCompleter (stub que
 * devolve JSON de fixture), sem realizar chamadas reais à API — requisito
 * dos testes do M4, pois os containers de CI não têm acesso à internet.
 *
 * O contrato recebe o prompt do sistema e a imagem (base64 + mimeType),
 * centralizando a montagem do client Gemini (api_key, model, schema,
 * generationConfig) na implementação concreta.
 */
interface ImageCompleter
{
    /**
     * Executa a geração multimodal (imagem + prompt) e devolve o conteúdo
     * textual da resposta (esperado: JSON estrito, garantido pelo
     * responseMimeType: APPLICATION_JSON + responseSchema).
     *
     * @param  string  $systemPrompt  Prompt do sistema (instruções de extração OCR).
     * @param  string  $base64Image  String base64 PURA da imagem (sem prefixo data URI).
     * @param  string  $mimeType  MIME type da imagem (ex.: "image/jpeg", "image/png").
     * @return string Conteúdo textual da resposta (JSON estrito).
     *
     * @throws ExtractionException Em falha de comunicação/timeout
     *                             (reason = API_ERROR) — a implementação concreta encapsula erros brutos.
     */
    public function complete(string $systemPrompt, string $base64Image, string $mimeType): string;
}
