<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Labels Inteligentes (feature de sugestão e formatação de etiquetas)
|--------------------------------------------------------------------------
|
| Parâmetros de runtime da feature de labels, consumidos por:
|
|  - {@see \App\Support\LabelFormatter}              — formatação canônica (MAX_LENGTH é constante interna).
|  - {@see \App\Actions\SuggestLabelsLLM}            — sugestão via LLM dedicado (M2).
|  - {@see \App\Services\DeepSeek\DeepSeekService}   — catálogo no prompt de extração (M1).
|  - {@see \App\Services\Gemini\GeminiService}       — catálogo no prompt de extração (M1).
|  - Fluxo conversacional (M3+)                      — fuzzy match entre labels do usuário e catálogo.
|
| Valores default calibrados para o volume de uso do bot (1 usuário, ~50
| transações/mês, ~15-30 labels únicas no catálogo). Ajustar via .env se
| necessário.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo Top-N
    |--------------------------------------------------------------------------
    |
    | Quantas labels do topo do ranking (ordenadas por `use_count DESC`) são
    | incluídas no catálogo injetado nos prompts de extração e sugestão.
    |
    | Valor default 15: cobre ~80% do uso real de labels sem inflar o prompt
    | com ruído (cada label extra custa tokens e aumenta latência).
    |
    */
    'catalog_top_n' => (int) env('LABELS_CATALOG_TOP_N', 15),

    /*
    |--------------------------------------------------------------------------
    | Máximo de Labels Sugeridas
    |--------------------------------------------------------------------------
    |
    | Teto de labels que a action de sugestão ({@see SuggestLabelsLLM}) pode
    | devolver em uma única chamada. Labels adicionais retornadas pelo LLM
    | são truncadas silenciosamente.
    |
    | Valor default 3: evita poluição visual nos botões inline (Telegram
    | limita a ~30 caracteres por botão; labels longas + várias opções
    | quebram o layout).
    |
    */
    'max_labels' => (int) env('LABELS_MAX_LABELS', 3),

    /*
    |--------------------------------------------------------------------------
    | Temperature da Sugestão LLM
    |--------------------------------------------------------------------------
    |
    | Temperature usada na chamada de completion do {@see SuggestLabelsLLM}.
    | Valor baixo (0.3) favorece determinismo — o catálogo já fornece as
    | opções, e o LLM deve apenas selecionar/ranquear, não "criar".
    |
    | Diferente da temperature de extração (0.1, mais determinística), aqui
    | aceitamos um pouco de variação para ranquear labels diferentes em
    | transações similares (ex.: "Almoço" vs "Restaurante").
    |
    */
    'suggestion_temperature' => (float) env('LABELS_SUGGESTION_TEMPERATURE', 0.3),

    /*
    |--------------------------------------------------------------------------
    | Threshold de Fuzzy Match
    |--------------------------------------------------------------------------
    |
    | Similaridade mínima (Levenshtein normalizado) para considerar que uma
    | label do usuário "bate" com uma label do catálogo. Usado no fluxo
    | conversacional (M3+) para decidir se uma label digitada pelo usuário
    | é uma variante ortográfica de uma label existente (ex.: "alimentacao"
    | vs "Alimentação" normalizado → 0.91) e deve ser unificada.
    |
    | Valor default 0.85: tolera 1-2 caracteres de diferença em palavras
    | de até ~10 caracteres (típico de labels PT-BR), mas rejeita palavras
    | genuinamente diferentes.
    |
    */
    'fuzzy_threshold' => (float) env('LABELS_FUZZY_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Mensagem de Loading do Wizard
    |--------------------------------------------------------------------------
    |
    | Mensagem exibida ao usuário enquanto as labels estão sendo sugeridas
    | (antes do teclado de confirmação). HTML inline do Telegram: <i> para
    | itálico, <b> para negrito. O emoji 💡 sinaliza "pensando/sugerindo".
    |
    */
    'wizard_loading_message' => '💡 <i>Sugerindo labels para sua transação...</i>',

];
