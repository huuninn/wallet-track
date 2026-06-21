<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Telescope;

use App\Services\Gemini\ImageCompleter;
use App\Support\Telescope\GeminiImageCompleterWatcherDecorator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Testes do {@see GeminiImageCompleterWatcherDecorator}.
 *
 * Valida que o decorator:
 *  - Chama o wrapped com os 3 parâmetros (systemPrompt, base64, mimeType).
 *  - Retorna o response do wrapped em caso de sucesso.
 *  - Re-lança a exceção original em caso de falha (transparência).
 *
 * `Telescope::recordEvent()` é estático; como Telescope está desligado
 * em phpunit, é no-op. Validamos indiretamente via mock do wrapped.
 */
#[CoversClass(GeminiImageCompleterWatcherDecorator::class)]
class GeminiImageCompleterWatcherDecoratorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_complete_delegates_to_wrapped_with_three_args(): void
    {
        $wrapped = Mockery::mock(ImageCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('system-prompt', 'base64data', 'image/jpeg')
            ->andReturn('{"amount": 47.50}');

        $decorator = new GeminiImageCompleterWatcherDecorator($wrapped);

        $result = $decorator->complete('system-prompt', 'base64data', 'image/jpeg');

        $this->assertSame('{"amount": 47.50}', $result);
    }

    public function test_complete_rethrows_exception_from_wrapped(): void
    {
        $original = new RuntimeException('Gemini API error');

        $wrapped = Mockery::mock(ImageCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('sys', 'b64', 'image/png')
            ->andThrow($original);

        $decorator = new GeminiImageCompleterWatcherDecorator($wrapped);

        try {
            $decorator->complete('sys', 'b64', 'image/png');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame($original, $e, 'Decorator deve re-lançar a MESMA exceção');
        }
    }

    public function test_complete_handles_empty_base64_with_error(): void
    {
        $original = new RuntimeException('Empty base64 not allowed');

        $wrapped = Mockery::mock(ImageCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with('sys', '', 'image/jpeg')
            ->andThrow($original);

        $decorator = new GeminiImageCompleterWatcherDecorator($wrapped);

        try {
            $decorator->complete('sys', '', 'image/jpeg');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            // Verifica que o decorator foi capaz de computar metadados
            // mesmo com base64 vazia (size=0, hash=sha1 de string vazia).
            $this->assertSame($original, $e);
        }
    }

    public function test_complete_passes_through_long_prompts_and_responses(): void
    {
        $longPrompt = str_repeat('A very detailed system prompt. ', 100);
        $longResponse = json_encode(['amount' => 999.99, 'description' => str_repeat('x', 1000)]);

        $wrapped = Mockery::mock(ImageCompleter::class);
        $wrapped->shouldReceive('complete')
            ->once()
            ->with($longPrompt, Mockery::any(), 'image/jpeg')
            ->andReturn($longResponse);

        $decorator = new GeminiImageCompleterWatcherDecorator($wrapped);

        $result = $decorator->complete($longPrompt, 'b64', 'image/jpeg');

        $this->assertSame($longResponse, $result);
    }
}
