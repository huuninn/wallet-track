<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Dto\TransactionData;

/**
 * Abstração do I/O do Telegram usado pelo {@see \App\Conversation\ConversationRouter}.
 *
 * **Princípio de design (M7)**: o Router é lógica pura testável que decide o
 * que fazer a partir de (input + sessão) e produz efeitos via esta interface.
 * O I/O real do Telegram (enviar mensagens, teclados inline, responder
 * callbacks, editar mensagens) vive atrás de uma implementação concreta
 * ({@see NutgramBotMessenger}) — o que permite um fake determinístico
 * ({@see InMemoryBotMessenger}) para testes.
 *
 * Isto replica o padrão gateway+fake usado em M3-M6 (ChatCompleter,
 * ImageCompleter, FirestoreGateway, SheetsGateway): a regra de negócio nunca
 * toca a SDK diretamente.
 *
 * Convenções de retorno:
 *  - Métodos que enviam uma mensagem nova (`sendText`, `sendConfirmationRequest`,
 *    `askForField`, `askForEdition`) devolvem o `message_id` Telegram — o
 *    Router o guarda em `sessions/{chat_id}.message_id_confirm` para:
 *      (a) rejeitar callbacks de mensagens antigas (CT-047);
 *      (b) editar o resumo in-place ao confirmar uma edição (M7.7).
 *  - `notifySuccess`/`notifyCancelled`/`notifyError`/`answerCallback`/
 *    `editMessageText` não devolvem nada (void): o Router não usa o
 *    `message_id` dessas mensagens subsequentemente.
 */
interface BotMessenger
{
    /**
     * Envia uma mensagem de texto simples ao chat.
     *
     * @param  int|string  $chatId  ID do chat Telegram.
     * @return int message_id da mensagem enviada.
     */
    public function sendText(int|string $chatId, string $text): int;

    /**
     * Envia o resumo da transação + inline keyboard [Confirmar][Editar][Cancelar].
     *
     * O resumo deve listar em PT-BR legível: Descrição, Valor, Tipo
     * (Despesa/Receita), Categoria (se houver) e Data.
     *
     * @param  string|null  $firestoreId  Opcional — apenas informativo (não
     *                                    exposto ao usuário; persisted só no confirm).
     * @return int message_id da mensagem com o keyboard.
     */
    public function sendConfirmationRequest(int|string $chatId, TransactionData $draft, ?string $firestoreId = null): int;

    /**
     * Pede ao usuário o valor de um campo pedível (AWAITING_DATA).
     *
     * @param  string  $field  "amount"|"type"|"date".
     * @param  string  $prompt  Texto já humanizado (ex.: "Qual o valor?").
     * @return int message_id da pergunta.
     */
    public function askForField(int|string $chatId, string $field, string $prompt): int;

    /**
     * Pede o novo valor de um campo em edição (AWAITING_EDITION).
     *
     * Distinto de {@see askForField()} para permitir mensagem/prompt diferente
     * (ex.: "Qual o novo valor?") e eventualmente um keyboard diferente no futuro.
     *
     * @param  string  $field  "amount"|"type"|"date"|"description"|"category".
     * @return int message_id da pergunta.
     */
    public function askForEdition(int|string $chatId, string $field): int;

    /**
     * Envia um inline keyboard com os campos editáveis para o usuário escolher.
     *
     * Botões (em PT-BR): [Valor][Tipo][Data][Descrição][Categoria] com
     * callback_data `edit:<field>`. Disparado quando o usuário toca "Editar"
     * no keyboard de confirmação — o usuário então escolhe qual campo quer
     * editar, e um novo callback `edit:<field>` chega ao
     * {@see \App\Conversation\ConversationRouter} (state permanece
     * AWAITING_CONFIRMATION até a escolha).
     *
     * @return int message_id da mensagem com o keyboard.
     */
    public function sendEditFieldPicker(int|string $chatId): int;

    /**
     * Responde (ack) a uma callback query do Telegram — remove o "carregando"
     * no cliente e opcionalmente mostra um toast.
     *
     * @param  string  $callbackId  ID do callback_query (Telegram).
     * @param  string  $text  Texto do toast (curto, <= 200 chars).
     */
    public function answerCallback(string $callbackId, string $text): void;

    /**
     * Edita o texto de uma mensagem enviada anteriormente.
     *
     * Usado em M7.7 para re-exibir o resumo in-place após uma edição
     * (mantendo o inline keyboard) e evitar acumular mensagens no chat.
     *
     * @param  int  $messageId  ID da mensagem original (geralmente = message_id_confirm).
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text): void;

    /**
     * Notifica o usuário que a transação foi registrada com sucesso.
     */
    public function notifySuccess(int|string $chatId, TransactionData $dto): void;

    /**
     * Notifica o usuário que a operação foi cancelada.
     */
    public function notifyCancelled(int|string $chatId): void;

    /**
     * Notifica o usuário de um erro amigável (PT-BR, sem detalhes técnicos).
     */
    public function notifyError(int|string $chatId, string $message): void;
}
