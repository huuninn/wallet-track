<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Telescope;

use App\Services\DeepSeek\ChatCompleter;
use App\Support\Telescope\DeepSeekChatCompleterWatcherDecorator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Testes do {@see DeepSeekChatCompleterWatcherDecorator}.
 *
 * Valida que o decorator:
 *  - Chama o wrapped com systemPrompt, userMessages, options.
 *  - Retorna o response do wrapped em caso de sucesso.
 *  - Re-lança a exceção original em caso de falha.
 *  - Lida com arrays de userMessages (multi-turn) e options customizadas.
 *
 * `Telescope::recordEvent()` é estático; como Telescope está desligado
 * em phpunit, é no-op. Validamos indiretamente via mock do wrapped.
 */
#[CoversClass(DeepSeekChatCompleterWatcherDecorator::class)]
class DeepSeekChatCompleterWatcherDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_complete_delegates_to_wrapped_with_three_args(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Paguei R$ 47,50 no almoço'],
        ];

        $wrapped = Mockery::mock(ChatCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('system-prompt', $messages, [])
            ->andReturn('{"amount": 47.50}');

        $decorator = new DeepSeekChatCompleterWatcherDecorator($wrapped);

        $result = $decorator->complete('system-prompt', $messages, []);

        $this->assertSame('{"amount": 47.50}', $result);
    }

    public function test_complete_rethrows_exception_from_wrapped(): void
    {
        $original = new RuntimeException('DeepSeek API error');

        $wrapped = Mockery::mock(ChatCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->andThrow($original);

        $decorator = new DeepSeekChatCompleterWatcherDecorator($wrapped);

        try {
            $decorator->complete('sys', [], []);
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e, 'Decorator deve re-lançar a MESMA exceção');
        }
    }

    public function test_complete_passes_through_multi_turn_user_messages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Comprei 3 itens no mercado'],
            ['role' => 'user', 'content' => 'Foram R$ 150,00 no total'],
        ];

        $wrapped = Mockery::mock(ChatCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('sys', $messages, Mockery::any())
            ->andReturn('{"amount": 150.00}');

        $decorator = new DeepSeekChatCompleterWatcherDecorator($wrapped);

        $result = $decorator->complete('sys', $messages, []);

        $this->assertSame('{"amount": 150.00}', $result);
    }

    public function test_complete_passes_through_custom_options(): void
    {
        $options = ['model' => 'deepseek-chat', 'temperature' => 0.1];
        $messages = [['role' => 'user', 'content' => 'test']];

        $wrapped = Mockery::mock(ChatCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('sys', $messages, $options)
            ->andReturn('{"ok": true}');

        $decorator = new DeepSeekChatCompleterWatcherDecorator($wrapped);

        $result = $decorator->complete('sys', $messages, $options);

        $this->assertSame('{"ok": true}', $result);
    }

    public function test_complete_with_empty_messages_array(): void
    {
        $original = new RuntimeException('No messages provided');

        $wrapped = Mockery::mock(ChatCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('', [], [])
            ->andThrow($original);

        $decorator = new DeepSeekChatCompleterWatcherDecorator($wrapped);

        try {
            $decorator->complete('', [], []);
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e);
        }
    }
}
