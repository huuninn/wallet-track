<?php

declare(strict_types=1);

namespace App\Bot\Messaging;

use App\Conversation\ConversationRouter;
use App\Dto\TransactionData;

/**
 * Abstração do I/O do Telegram usado pelo {@see ConversationRouter}.
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
     * {@see ConversationRouter} (state permanece
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
     *
     * @deprecated R2: nenhuma mensagem é editada in-place. O Router envia
     *             nova mensagem via sendText/sendConfirmationRequest. Mantido
     *             para retrocompatibilidade de testes.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text): void;

    /**
     * Edita APENAS o inline keyboard de uma mensagem, mantendo o texto original.
     *
     * Usado para "consumir" o picker de campos (enviado via
     * {@see sendEditFieldPicker}) sem deletar a mensagem — o texto
     * "✏️ Qual campo você quer editar?" permanece como histórico no chat,
     * mas os botões interativos desaparecem (evita cliques errados do
     * usuário em botões velhos durante AWAITING_EDITION).
     *
     * Comportamento:
     *  - `$markup = null` ou `[]` → remove o keyboard, mantém texto
     *  - `$markup` array não-vazio → substitui pelo novo keyboard
     *
     * Best-effort: mesma política de {@see deleteMessage} — silencioso em
     * caso de falha (a sessão/UX não pode quebrar por causa de um erro
     * de rede).
     *
     * @param  int|string  $chatId
     * @param  int  $messageId  ID da mensagem original
     * @param  array<mixed>|null  $markup  Keyboard de substituição, ou null para remover
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $markup): void;

    /**
     * Deleta uma mensagem enviada anteriormente no chat.
     *
     * Usado para remover o picker de campos (enviado via
     * {@see sendEditFieldPicker}) após o usuário escolher qual campo
     * editar, confirmar ou cancelar — evitando poluição visual no chat.
     *
     * Comportamento best-effort:
     *  - Se a mensagem já foi deletada (pelo usuário ou por outra chamada),
     *    a implementação DEVE capturar o erro e retornar silenciosamente
     *    (idempotente). NUNCA deve lançar exceção que interrompa o fluxo.
     *  - Falhas de rede/API são logadas mas NÃO propagadas.
     *
     * @param  int|string  $chatId  ID do chat Telegram.
     * @param  int  $messageId  ID da mensagem a ser deletada.
     */
    public function deleteMessage(int|string $chatId, int $messageId): void;

    /**
     * Restaura o inline keyboard de confirmação (✅ Confirmar / ✏️ Editar / ❌ Cancelar)
     * em uma mensagem X existente. Usado após edição bem-sucedida: o teclado de X
     * foi removido ao clicar em "Editar" (decisão 1A), e precisa ser restaurado ao
     * concluir a edição para que o usuário possa confirmar/cancelar/editar novamente.
     *
     * @deprecated R2: nenhuma mensagem é editada in-place. O Router envia
     *             nova mensagem via sendText/sendConfirmationRequest. Mantido
     *             para retrocompatibilidade de testes.
     */
    public function restoreConfirmationKeyboard(int|string $chatId, int $messageId): void;

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
