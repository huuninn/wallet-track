<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Enums\ConversationState;
use LogicException;

/**
 * Tabela de transições legais da máquina de estados conversacional (M7.2).
 *
 * O {@see ConversationRouter} consulta {@see canTransition()} antes de
 * efetivamente gravar um novo `state` na sessão no banco de dados — isso protege
 * contra bugs sutis onde um callback inesperado poderia pular de
 * AWAITING_EDITION direto para persistência, por exemplo.
 *
 * Tabela legal (vide docs/06-plano-implementacao.md §7):
 *
 *  - IDLE                 → AWAITING_DATA | AWAITING_CONFIRMATION
 *  - AWAITING_DATA        → AWAITING_DATA | AWAITING_CONFIRMATION | IDLE
 *  - AWAITING_CONFIRMATION → IDLE | AWAITING_EDITION
 *  - AWAITING_EDITION     → AWAITING_CONFIRMATION | AWAITING_DATA | AWAITING_EDITION
 *
 * Self-transições (ex.: AWAITING_DATA → AWAITING_DATA ao re-perguntar o
 * mesmo campo após resposta inválida) são aceitas explicitamente — o
 * `updated_at` da sessão é renovado, mas o `state` permanece.
 *
 * A classe é pura e sem dependências: trivialmente testável e cacheável
 * como singleton.
 */
final class StateMachine
{
    /**
     * Tabela indexada por estado-origem; valor = lista de estados-destino legais.
     *
     * @return array<value-of<ConversationState>, list<value-of<ConversationState>>>
     */
    private function transitions(): array
    {
        return [
            ConversationState::IDLE->value => [
                ConversationState::AWAITING_DATA->value,
                ConversationState::AWAITING_CONFIRMATION->value,
                ConversationState::IDLE->value,
            ],
            ConversationState::AWAITING_DATA->value => [
                ConversationState::AWAITING_DATA->value,
                ConversationState::AWAITING_CONFIRMATION->value,
                ConversationState::IDLE->value,
            ],
            ConversationState::AWAITING_CONFIRMATION->value => [
                ConversationState::IDLE->value,
                ConversationState::AWAITING_EDITION->value,
                ConversationState::AWAITING_CONFIRMATION->value,
            ],
            ConversationState::AWAITING_EDITION->value => [
                ConversationState::AWAITING_CONFIRMATION->value,
                ConversationState::AWAITING_DATA->value,
                ConversationState::AWAITING_EDITION->value,
                ConversationState::IDLE->value,
            ],
        ];
    }

    /**
     * Indica se a transição `$from → $to` é legal conforme a tabela.
     *
     * Self-transições são aceitas (a tabela as inclui explicitamente para
     * não depender de um `||` adicional no caller).
     */
    public function canTransition(ConversationState $from, ConversationState $to): bool
    {
        $allowed = $this->transitions()[$from->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    /**
     * Lança LogicException se a transição for ilegal.
     *
     * Usado por código que já validou invariantes de negócio e quer falhar
     * rápido em programação errada (defensivo — não deve ocorrer em runtime
     * normal se o Router estiver correto).
     *
     * @throws LogicException
     */
    public function assertCanTransition(ConversationState $from, ConversationState $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new LogicException(
                "Transição de estado ilegal: {$from->value} → {$to->value}.",
            );
        }
    }
}
