<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Bot\Handlers\HelpHandler;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use Tests\TestCase;

/**
 * Testes do {@see HelpHandler} (M9.2 / T-002 / GAP-03).
 *
 * Garante que `/help`:
 *  - Lista 7 comandos (CT-024);
 *  - Marca todos os 5 comandos do M9 como ativos no M9 final (CT-024b);
 *  - Usa HTML e emojis (CT-024c);
 *  - É stateless — não altera sessão (CT-024a: estado preservado).
 */
#[CoversClass(HelpHandler::class)]
class HelpHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Group('smoke')]
    public function test_help_message_lists_seven_commands(): void
    {
        $this->assertCount(7, HelpHandler::commands(), 'HelpHandler::commands() deve listar 7 comandos');

        $text = HelpHandler::message();
        // Cada comando aparece literalmente na mensagem.
        $this->assertStringContainsString('/start', $text);
        $this->assertStringContainsString('/help', $text);
        $this->assertStringContainsString('/nova', $text);
        $this->assertStringContainsString('/cancelar', $text);
        $this->assertStringContainsString('/ultimos', $text);
        $this->assertStringContainsString('/categorias', $text);
        $this->assertStringContainsString('/sync', $text);
    }

    public function test_help_all_m9_commands_marked_active(): void
    {
        // CT-024b: regressão — todos os 7 comandos devem estar `active=true`.
        // GAP-03: o handler atual marcava 5 deles como false; T-002 corrige.
        $expectedActive = ['/start', '/help', '/nova', '/cancelar', '/ultimos [n]', '/categorias', '/sync'];

        $actualActive = [];
        foreach (HelpHandler::commands() as [$command, , $active]) {
            if ($active) {
                $actualActive[] = $command;
            }
        }

        $this->assertEqualsCanonicalizing($expectedActive, $actualActive);

        // A mensagem final deve ter pelo menos 7 ocorrências de ✅ (uma por
        // comando + 1 na legenda "✅ ativo").
        $this->assertGreaterThanOrEqual(
            7,
            substr_count(HelpHandler::message(), '✅'),
            'Mensagem do /help deve ter ao menos 7 ocorrências de ✅ (CT-024b)',
        );
    }

    public function test_help_message_uses_html_and_emojis(): void
    {
        // CT-024c: formatação HTML + emojis típicos.
        $text = HelpHandler::message();

        $this->assertStringContainsString('<b>', $text);
        $this->assertStringContainsString('<code>', $text);
        $this->assertStringContainsString('🆘', $text);
        $this->assertStringContainsString('💬', $text);
    }

    public function test_help_invoke_does_not_alter_session_or_send_nothing_via_state(): void
    {
        // CT-024a: o handler /help é puramente leitura — não lê nem escreve
        // sessão. Verificamos que a única chamada ao Nutgram é sendMessage.
        $message = new Message(null);
        $message->chat = new Chat(null);
        $message->chat->id = 12345;

        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('message')->never();   // /help não lê message()
        $bot->shouldReceive('sendMessage')->once()->andReturnUsing(
            function (string $text, ?string $parse_mode = null) {
                $this->assertStringContainsString('Comandos do Wallet Track', $text);

                return null;
            }
        );

        (new HelpHandler)($bot);
    }
}
