<?php

declare(strict_types=1);

use Gemini\Data\Schema;
use Gemini\Enums\DataType;

/**
 * Schema de resposta JSON para a extração multimodal do Gemini (spec §8.2).
 *
 * Retorna um {@see Schema} (objeto do pacote google-gemini-php/client) que
 * descreve a estrutura do JSON esperado como saída. Combinado com
 * responseMimeType: APPLICATION_JSON, o Gemini garante saída JSON estrita
 * aderente a este schema.
 *
 * Decisão de design — `required` intencionalmente VAZIO: campos críticos
 * (description, amount, type) podem vir null quando a imagem não contém uma
 * transação clara (fallback M4.6 — CT-008/CT-009). Se os marcássemos como
 * required, o Gemini seria forçado a inventar valores, violando a regra
 * "nunca inventar dados". Apenas `labels` é obrigatório (sempre um array,
 * possivelmente vazio).
 *
 * Consumido por App\Services\Gemini\GeminiImageCompleter.
 */
return new Schema(
    type: DataType::OBJECT,
    properties: [
        'description' => new Schema(
            type: DataType::STRING,
            description: 'Nome do estabelecimento ou descrição da transação. null se ilegível ou se a imagem não é uma nota fiscal.',
            nullable: true,
        ),
        'amount' => new Schema(
            type: DataType::NUMBER,
            description: 'Valor TOTAL da nota (não parcial). Número positivo. null se ilegível.',
            nullable: true,
        ),
        'type' => new Schema(
            type: DataType::STRING,
            description: 'Tipo da transação: "expense" (padrão para notas) ou "income".',
            enum: ['expense', 'income'],
            nullable: true,
        ),
        'category' => new Schema(
            type: DataType::STRING,
            description: 'Categoria sugerida em PT-BR (ex.: Mercado, Transporte). null se sem contexto.',
            nullable: true,
        ),
        'labels' => new Schema(
            type: DataType::ARRAY,
            description: 'Etiquetas curtas para busca, em PT-BR capitalizadas (primeira letra maiúscula, acentos preservados, marcas conhecidas preservadas como iFood, PIX). Máximo 3 labels. Ex.: ["Almoço", "iFood"]. Array vazio se não houver.',
            items: new Schema(type: DataType::STRING),
        ),
        'date' => new Schema(
            type: DataType::STRING,
            description: 'Data da transação em ISO YYYY-MM-DD.',
            nullable: true,
        ),
        'observations' => new Schema(
            type: DataType::STRING,
            description: 'Detalhes adicionais: CNPJ, forma de pagamento. null se nada relevante.',
            nullable: true,
        ),
        'confidence' => new Schema(
            type: DataType::NUMBER,
            description: 'Confiança na extração (0.0 a 1.0).',
        ),
    ],
    required: ['labels'],
);
