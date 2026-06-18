<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Handler do comando /start.
 *
 * Mensagem de boas-vindas em PT-BR explicando o que o bot faz (controle
 * financeiro pessoal via texto livre OU foto de nota fiscal) e listando os
 * comandos disponíveis. Stateless: responde uma única mensagem por invocação.
 *
 * Ref.: docs/02-especificacao-tecnica.md §7 (comandos), docs/06-plano-implementacao.md §4.3 (M1.2/M1.5).
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
        $bot->sendMessage(
            text: self::message(),
            parse_mode: ParseMode::HTML,
        );
    }
}
