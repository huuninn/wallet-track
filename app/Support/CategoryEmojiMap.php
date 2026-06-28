<?php

declare(strict_types=1);

namespace App\Support;

use App\Bot\Handlers\CategoriasHandler;
use App\Bot\Messaging\TransactionSummaryFormatter;

/**
 * Mapa centralizado de categorias → emoji (M9 review W-1).
 *
 * Single source of truth para a tabela de emojis por categoria. Usado por:
 *
 *  - {@see CategoriasHandler} — renderização da listagem
 *    do comando `/categorias`.
 *  - {@see TransactionSummaryFormatter} — linha da
 *    categoria na listagem compacta do `/ultimos`.
 *
 * **Por que centralizar?** A spec §6.3 fixa o mesmo mapa de 9 categorias
 * padrão (mais fallback genérico). Manter duas cópias idênticas em
 * `CategoriasHandler` e `TransactionSummaryFormatter` é uma bomba-relógio
 * de divergência — adicionar uma categoria nova ou trocar um emoji
 * poderia passar despercebido por uma das duas constantes. Esta classe
 * garante que ambas renderizações usem EXATAMENTE o mesmo mapa.
 *
 * **Por que `final readonly class`?** A classe não tem estado (é um
 * repositório de constantes utilitárias) — não há razão para herança ou
 * mutação. `final readonly` torna a intenção explícita e dá ao compilador
 * sinal verde para otimizações (a tabela de emojis vira effectively-final
 * em todo call site).
 *
 * **Adicionar uma categoria nova**: basta editar o array `EMOJIS` abaixo.
 * As duas renderizações acompanharão automaticamente.
 */
final readonly class CategoryEmojiMap
{
    /**
     * Mapa canônico categoria → emoji.
     *
     * Alinhado com a spec §6.3 (9 categorias padrão do Wallet Track). A
     * busca é case-sensitive: a categoria é o `display_name` exato lido
     * do banco de dados. Categorias personalizadas fora desta tabela recebem
     * o fallback (consultar {@see self::get()}).
     *
     * @var array<string, string>
     */
    public const array EMOJIS = [
        'Alimentação' => '🍕',
        'Transporte' => '🚗',
        'Moradia' => '🏠',
        'Saúde' => '❤️',
        'Educação' => '📚',
        'Lazer' => '🎮',
        'Salário' => '💰',
        'Freelance' => '💻',
        'Outros' => '📦',
    ];

    /**
     * Devolve o emoji correspondente à categoria, com fallback genérico.
     *
     * Categorias fora do mapa canônico (ex.: personalizadas como "Pet",
     * "Hobbies") recebem `📦` (mesmo emoji da categoria "Outros") — não o
     * `🏷` (que é o fallback usado nas linhas de listagem). Esta escolha
     * mantém {@see self::get()} consistente com a spec §6.3: a tabela
     * cobre 9 categorias e "fora da tabela" cai no "Outros" genérico.
     *
     * Renderizações que precisam de um fallback DIFERENTE (ex.: `🏷` para
     * sinalizar "categoria personalizada") devem usar {@see self::EMOJIS}
     * diretamente com `?? '🏷'`.
     *
     * @param  string  $category  Nome canônico da categoria (case-sensitive).
     */
    public static function get(string $category): string
    {
        return self::EMOJIS[$category] ?? '📦';
    }

    /**
     * Devolve o mapa completo (cópia rasa). Útil para debug, logging ou
     * para iteração (ex.: popular dropdowns em alguma UI futura).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::EMOJIS;
    }
}
