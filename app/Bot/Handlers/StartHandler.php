<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Services\Google\FirestoreService;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Handler do comando /start (M9.1 — T-001).
 *
 * Mensagem de boas-vindas em PT-BR explicando o que o bot faz (controle
 * financeiro pessoal via texto livre OU foto de nota fiscal) e listando os
 * comandos disponíveis.
 *
 * **GAP-01 (M9 / Portão 2)**: este handler AGORA limpa a sessão do chat antes
 * de enviar a mensagem. Isso garante que `/start` em qualquer estado
 * (AWAITING_DATA, AWAITING_CONFIRMATION, AWAITING_EDITION) volte o chat para
 * IDLE, descartando drafts/transações pendentes. Comportamento esperado pelos
 * CT-023a, CT-023b, CT-023c, CT-023f do plano de testes M9.
 *
 * Se a limpeza da sessão falhar (ex.: Firestore indisponível), o handler
 * registra o erro no log mas ainda envia a mensagem de boas-vindas — o
 * `/start` é considerado best-effort e nunca deve retornar 5xx para o
 * Telegram. Em caso de crash, o usuário pode tentar `/start` novamente ou
 * aguardar o timeout da sessão (15min).
 *
 * Ref.: docs/02-especificacao-tecnica.md §7 (comandos), docs/06-plano-implementacao.md §4.3 (M1.2/M1.5),
 *       docs/planos/m9-plano-tecnico.md (T-001, GAP-01).
 */
class StartHandler
{
    /**
     * Texto de boas-vindas exposto como método estático para permitir
     * validação isolada do conteúdo (PT-BR + comandos) em testes.
     */
    public static function message(): string
    {
        return <<<'HTML'
👋 <b>Olá! Sou o Wallet Track</b>

Seu assistente de <b>controle financeiro pessoal</b> no Telegram. Registre transações de duas formas:

📝 <b>Texto livre</b> — digite algo como:
   <i>"Gastei R$ 45,90 no almoço de ontem"</i>
📷 <b>Foto de nota fiscal</b> — envie uma imagem da cupom/nota e eu extraio os dados.

Depois é só <b>confirmar</b> e a transação vai para sua planilha do Google Sheets 📊.

<b>Comandos disponíveis:</b>
/start — esta mensagem de boas-vindas
/help — lista completa de comandos

Use <b>/help</b> para ver todos os comandos (inclusive os que estão por vir).
HTML;
    }

    public function __invoke(Nutgram $bot): void
    {
        // T-001: limpa a sessão antes da mensagem (GAP-01, CT-023a-f).
        // Defensivo: se a mensagem for null (improvável em comando roteado),
        // saímos sem ação — o `message()` do Nutgram sempre popula para
        // comandos de barra.
        $message = $bot->message();
        if ($message !== null) {
            $chatId = (string) (int) $message->chat->id;
            try {
                app(FirestoreService::class)->clearSession($chatId);
            } catch (\Throwable $e) {
                // Best-effort: loga e segue. O `/start` nunca pode quebrar
                // a UX por falha de Firestore — o usuário ainda recebe a
                // mensagem de boas-vindas.
                Log::warning('StartHandler: clearSession falhou', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $bot->sendMessage(
            text: self::message(),
            parse_mode: ParseMode::HTML,
        );
    }
}
