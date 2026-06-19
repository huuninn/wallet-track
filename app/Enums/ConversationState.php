<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estados da máquina de estados conversacional do Wallet Track (M7.1).
 *
 * Cada chat tem uma sessão Firestore (`sessions/{chat_id}`) cujo campo
 * `state` armazena o valor (string) deste enum. O {@see \App\Conversation\StateMachine}
 * valida que as transições entre estados seguem o fluxo legal descrito em
 * docs/06-plano-implementacao.md §7 (M7.2).
 *
 * Estados:
 *
 *  - IDLE                 → sem transação em andamento; input livre vira extração.
 *  - AWAITING_DATA        → esperando o usuário responder um campo pedível
 *                           (valor/tipo/data) detectado como null no DTO.
 *  - AWAITING_CONFIRMATION → transação extraída + completa, aguardando o
 *                            usuário tocar Confirmar/Editar/Cancelar.
 *  - AWAITING_EDITION     → esperando o usuário digitar novo valor para um
 *                           campo editável (após clicar "Editar").
 *
 * Backed string para serialização trivial em Firestore (campo `state`).
 */
enum ConversationState: string
{
    /** Sem transação em andamento; pronto para nova extração. */
    case IDLE = 'idle';

    /** Aguardando o usuário responder um campo pedível (valor/tipo/data). */
    case AWAITING_DATA = 'awaiting_data';

    /** Transação completa extraída; aguardando Confirmação/Edição/Cancelamento. */
    case AWAITING_CONFIRMATION = 'awaiting_confirmation';

    /** Aguardando o usuário digitar o novo valor de um campo em edição. */
    case AWAITING_EDITION = 'awaiting_edition';

    /**
     * Desserializa a partir do valor guardado na sessão Firestore.
     *
     * O campo `state` é gravado como string; chamadas como
     * `ConversationState::from($session['state'])` seriam frágeis se um
     * estado legacy/desconhecido fosse encontrado. Este helper trata o caso
     * devolvendo IDLE como fallback seguro — uma sessão desconhecida é
     * tratada como "sem estado", evitando travar o fluxo conversacional.
     */
    public static function fromSession(mixed $value): self
    {
        if (! is_string($value)) {
            return self::IDLE;
        }

        return self::tryFrom($value) ?? self::IDLE;
    }
}
