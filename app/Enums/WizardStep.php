<?php

declare(strict_types=1);

namespace App\Enums;

use App\Conversation\WizardHandler;
use App\Dto\TransactionData;

/**
 * Etapas do wizard `/nova` (M9.3 / T-018).
 *
 * O wizard é uma sequência fixa e linear de campos pedíveis. Cada case
 * representa o próximo campo a ser solicitado ao usuário, **na ordem em que
 * aparece no enum** (1 → 2 → 3 → 4 → 5). A etapa 6 (`CONFIRMATION`) é a
 * "saída" do wizard — quando `pickNextAwaitingField` retorna `null` e o
 * `ConversationRouter` chama `presentConfirmation()`.
 *
 * Sequência canônica (Portão 2 / Decisão #5 — especificada nas Clarificações):
 *
 * ```
 *   1. TYPE         → "despesa" ou "receita"
 *   2. AMOUNT       → valor monetário (parser tolerante — ver validateAmount)
 *   3. DESCRIPTION  → texto livre, 2-500 chars
 *   4. CATEGORY     → nome da categoria (Decisão #13: Opção A = texto livre)
 *   5. LABELS       → CSV (ou "pular" / "-" para vazio)
 *   6. CONFIRMATION → resumo + inline keyboard [Confirmar][Editar][Cancelar]
 * ```
 *
 * O `ConversationRouter` armazena o step atual no campo `draft._wizard_step`
 * (int) e o flag `draft._wizard_active` (bool) na sessão Firestore.
 *
 * Backed int para serialização trivial no Firestore (campo `_wizard_step`).
 */
enum WizardStep: int
{
    /** 1. Tipo da transação ("despesa" / "receita"). */
    case TYPE = 1;

    /** 2. Valor monetário (parser tolerante: `47,50` / `R$ 47,50` / `47.50`). */
    case AMOUNT = 2;

    /** 3. Descrição textual (2–500 caracteres, trim). */
    case DESCRIPTION = 3;

    /** 4. Categoria (string livre; Decisão #13 = Opção A: digitar texto). */
    case CATEGORY = 4;

    /** 5. Labels separadas por vírgula (ou "pular" / "-" para vazio). */
    case LABELS = 5;

    /** 6. Confirmação — saída do wizard (resumo + keyboard). */
    case CONFIRMATION = 6;

    /**
     * Mapeia o case para o nome do campo no DTO (usado por `withField`).
     *
     * Mantém um único ponto de verdade: a constante `name` em cada case é
     * apenas a label humana; esta função é a referência canônica para o
     * nome do campo real no {@see TransactionData}.
     */
    public function fieldName(): string
    {
        return match ($this) {
            self::TYPE => 'type',
            self::AMOUNT => 'amount',
            self::DESCRIPTION => 'description',
            self::CATEGORY => 'category',
            self::LABELS => 'labels',
            self::CONFIRMATION => '',
        };
    }

    /**
     * Devolve o próximo step, ou null se já estamos no fim (CONFIRMATION).
     *
     * Usado pelo {@see WizardHandler} para avançar a
     * sequência. Encapsulado aqui para evitar `match` espalhado no Router.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::TYPE => self::AMOUNT,
            self::AMOUNT => self::DESCRIPTION,
            self::DESCRIPTION => self::CATEGORY,
            self::CATEGORY => self::LABELS,
            self::LABELS => self::CONFIRMATION,
            self::CONFIRMATION => null,
        };
    }

    /**
     * Versão "amigável" do step (label humana, em PT-BR) — usada em PHPDoc,
     * logs e mensagens de erro.
     */
    public function label(): string
    {
        return match ($this) {
            self::TYPE => 'Tipo',
            self::AMOUNT => 'Valor',
            self::DESCRIPTION => 'Descrição',
            self::CATEGORY => 'Categoria',
            self::LABELS => 'Labels',
            self::CONFIRMATION => 'Confirmação',
        };
    }

    /**
     * Total de etapas pedíveis (TYPE até LABELS). CONFIRMATION não conta
     * porque não é uma pergunta — é a tela de revisão final.
     */
    public const int TOTAL_ASKED_STEPS = 5;
}
