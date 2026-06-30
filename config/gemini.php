<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini (extração de imagem → JSON / OCR multimodal)
    |--------------------------------------------------------------------------
    |
    | Configuração do Google Gemini (AI Studio), consumido via
    | `google-gemini-php/client`. Usado em M4 pela GeminiService para
    | extrair transações a partir de fotos de notas fiscais (OCR multimodal).
    |
    | Veja docs/02-especificacao-tecnica.md §8.2 (prompt multimodal +
    | responseSchema) e §12 (envvars).
    |
    | Auth: API Key simples (AI Studio), NÃO service account.
    |
    */

    // API Key obtida em https://aistudio.google.com/app/apikey.
    'api_key' => env('GEMINI_API_KEY'),

    // Identificador do modelo multimodal. gemini-2.5-flash (preview) é a
    // primeira escolha; fallback documentado: gemini-2.0-flash (GA estável).
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    // Temperature baixa para máximo determinismo na extração estruturada.
    'temperature' => (float) env('GEMINI_TEMPERATURE', '0.1'),

    // Timeout HTTP em segundos. OCR de imagem pode levar alguns segundos;
    // O ambiente de produção (VPS) permite até 300s no worker Octane. O cliente usa PSR-18 discovery.
    'timeout' => (int) env('GEMINI_TIMEOUT', '60'),

];
