<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | DeepSeek (extração de texto → JSON)
    |--------------------------------------------------------------------------
    |
    | Configuração do DeepSeek, consumido via `openai-php/client` (a API da
    | DeepSeek é compatível com o endpoint OpenAI, mudando apenas a base_url).
    | Usado em M3 pela DeepSeekService para extrair transações de texto livre.
    |
    | Veja docs/02-especificacao-tecnica.md §8.1 (prompt) e §12 (envvars).
    |
    | Nota sobre o modelo: a spec histórica cita "deepseek-v4-flash" (nome
    | hipotético inexistente). O identificador real do modelo general-purpose
    | da DeepSeek é "deepseek-chat" — ver docs/viability-report.md §4.4.
    |
    */

    // API Key obtida em https://platform.deepseek.com.
    'api_key' => env('DEEPSEEK_API_KEY'),

    // Base URL da API (compatível com OpenAI). O `openai-php/client` usa
    // este valor como base para as requisições de chat/completions.
    'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),

    // Identificador do modelo (deepseek-chat = general-purpose atual).
    'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),

    // Temperature baixa para máxima determinismo na extração estruturada.
    'temperature' => (float) env('DEEPSEEK_TEMPERATURE', '0.1'),

    // Timeout HTTP em segundos (documentado; o openai-php/client 0.20 não
    // expõe setter de timeout direto na Factory, então o limite efetivo fica
    // a cargo do cliente PSR-18 subjacente. Mantido para uso futuro/M10).
    'timeout' => (int) env('DEEPSEEK_TIMEOUT', '30'),

];
