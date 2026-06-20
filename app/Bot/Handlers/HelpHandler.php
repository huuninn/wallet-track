<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Handler do comando /help (M9.2 — T-002).
 *
 * Lista TODOS os comandos planejados para o bot, marcando quais estão
 * ativos no milestone atual. No M9 final, todos os 7 comandos planejados
 * aparecem com `✅ ativo`.
 *
 * **GAP-03 (M9 / Portão 2)**: as flags `active` dos comandos do M9 foram
 * marcadas como `true` (eram `false` no M1). Isso reflete que `/nova`,
 * `/cancelar`, `/ultimos`, `/categorias` e `/sync` são todos funcionais
 * (mesmo que `/nova` e `/sync` sejam entregues em Fases D e C do M9.1
 * respectivamente — no M9 final ambos estarão ativos). Atende CT-024b.
 *
 * O handler é **stateless** (não toca sessão) e não altera estado — atende
 * CT-024a: `/help` em AWAITING_CONFIRMATION preserva a transação pendente.
 *
 * Ref.: docs/02-especificacao-tecnica.md §7 (comandos), docs/06-plano-implementacao.md §4.3 (M1.2/M1.5),
 *       docs/planos/m9-plano-tecnico.md (T-002, GAP-03).
 */
class HelpHandler
{
    /**
     * Lista canônica de comandos planejados e seu status de implementação.
     * Cada item: [comando, descrição, ativo?].
     * Manter sincronizado com a Especificação Técnica §7.
     *
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public static function commands(): array
    {
        return [
            ['/start', 'Boas-vindas e instruções iniciais', true],
            ['/help', 'Lista de comandos e exemplos', true],
            ['/nova', 'Cadastro passo a passo (Tipo → Valor → Descrição → Categoria → Labels)', true],
            ['/cancelar', 'Cancela a operação atual e volta ao início', true],
            ['/ultimos [n]', 'Últimas N transações (padrão 5, máx 50)', true],
            ['/categorias', 'Lista categorias disponíveis', true],
            ['/sync', 'Dispara a sincronização de transações pendentes', true],
        ];
    }

    /**
     * Texto de ajuda exposto como método estático para validação isolada.
     */
    public static function message(): string
    {
        $lines = [];
        foreach (self::commands() as [$command, $description, $active]) {
            $marker = $active ? '✅' : '⏳';
            $lines[] = "{$marker} <code>{$command}</code> — {$description}";
        }

        $body = implode("\n", $lines);

        return <<<HTML
🆘 <b>Comandos do Wallet Track</b>

{$body}

<b>Legenda:</b> ✅ ativo &nbsp; ⏳ em breve

💬 <b>Dica:</b> além dos comandos, você pode simplesmente escrever uma transação
em linguagem natural (ex.: <i>"Recebi salário de R$ 5000"</i>) ou enviar a foto
de uma nota fiscal.
HTML;
    }

    /**
     * Invoca o handler: envia a mensagem de ajuda com a lista canônica
     * de comandos (CT-024). Stateless — não lê nem escreve sessão.
     *
     * @param  Nutgram  $bot  Instância do bot injetada pelo BotLoader.
     */
    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: self::message(),
            parse_mode: ParseMode::HTML,
        );
    }
}
