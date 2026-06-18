<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;

/**
 * Handler do comando /help.
 *
 * Lista TODOS os comandos planejados para o bot (mesmo os ainda não
 * implementados), marcando quais estão ativos no milestone atual (M1).
 * Stateless: responde uma única mensagem por invocação.
 *
 * Ref.: docs/02-especificacao-tecnica.md §7 (comandos), docs/06-plano-implementacao.md §4.3 (M1.2/M1.5).
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
            ['/nova', 'Cadastro passo a passo (Tipo → Valor → Descrição → Categoria → Labels)', false],
            ['/cancelar', 'Cancela a operação atual e volta ao início', false],
            ['/ultimos [n]', 'Últimas N transações (padrão 5, máx 50)', false],
            ['/categorias', 'Lista categorias disponíveis', false],
            ['/sync', 'Dispara a sincronização de transações pendentes', false],
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

    public function __invoke(Nutgram $bot): void
    {
        $bot->sendMessage(
            text: self::message(),
            parse_mode: ParseMode::HTML,
        );
    }
}
