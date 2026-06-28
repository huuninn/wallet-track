<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CategoryEmojiMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes do {@see CategoryEmojiMap} (M9 review W-1).
 *
 * Cobertura:
 *  - Tabela canônica tem exatamente 9 entradas (spec §6.3).
 *  - {@see CategoryEmojiMap::get()} retorna o emoji correto para categorias
 *    conhecidas e o fallback `📦` para desconhecidas.
 *  - {@see CategoryEmojiMap::all()} devolve o mapa completo.
 *  - A classe é `final readonly` (sem estado mutável).
 *
 * Roda isolado: bin/dev test --filter CategoryEmojiMapTest
 */
#[CoversClass(CategoryEmojiMap::class)]
class CategoryEmojiMapTest extends TestCase
{
    public function test_emojis_map_has_nine_default_categories(): void
    {
        // Spec §6.3 fixa 9 categorias padrão. Mudar este número é quebra
        // de contrato — qualquer regressão aqui deve ser investigada.
        $this->assertCount(9, CategoryEmojiMap::EMOJIS);
        $this->assertCount(9, CategoryEmojiMap::all());
    }

    public function test_get_returns_emoji_for_known_category(): void
    {
        $this->assertSame('🍕', CategoryEmojiMap::get('Alimentação'));
        $this->assertSame('🚗', CategoryEmojiMap::get('Transporte'));
        $this->assertSame('🏠', CategoryEmojiMap::get('Moradia'));
        $this->assertSame('❤️', CategoryEmojiMap::get('Saúde'));
        $this->assertSame('📚', CategoryEmojiMap::get('Educação'));
        $this->assertSame('🎮', CategoryEmojiMap::get('Lazer'));
        $this->assertSame('💰', CategoryEmojiMap::get('Salário'));
        $this->assertSame('💻', CategoryEmojiMap::get('Freelance'));
        $this->assertSame('📦', CategoryEmojiMap::get('Outros'));
    }

    public function test_get_returns_fallback_for_unknown_category(): void
    {
        // Categorias personalizadas (Pet, Hobbies) ou strings vazias caem
        // no fallback genérico `📦` — o mesmo emoji de "Outros".
        $this->assertSame('📦', CategoryEmojiMap::get('Pet'));
        $this->assertSame('📦', CategoryEmojiMap::get('Hobbies'));
        $this->assertSame('📦', CategoryEmojiMap::get('Inexistente'));
        $this->assertSame('📦', CategoryEmojiMap::get(''));
    }

    public function test_get_is_case_sensitive(): void
    {
        // A busca é case-sensitive: a categoria é o `display_name` exato
        // do banco de dados. "alimentação" minúscula NÃO é reconhecida —
        // preserva o nome original (spec §6.3).
        $this->assertSame('📦', CategoryEmojiMap::get('alimentação'));
        $this->assertSame('📦', CategoryEmojiMap::get('ALIMENTAÇÃO'));
    }

    public function test_all_returns_complete_map(): void
    {
        $map = CategoryEmojiMap::all();

        $this->assertIsArray($map);
        $this->assertArrayHasKey('Alimentação', $map);
        $this->assertArrayHasKey('Transporte', $map);
        $this->assertArrayHasKey('Moradia', $map);
        $this->assertArrayHasKey('Saúde', $map);
        $this->assertArrayHasKey('Educação', $map);
        $this->assertArrayHasKey('Lazer', $map);
        $this->assertArrayHasKey('Salário', $map);
        $this->assertArrayHasKey('Freelance', $map);
        $this->assertArrayHasKey('Outros', $map);

        // Os valores são todos strings não-vazios.
        foreach ($map as $name => $emoji) {
            $this->assertIsString($emoji, "emoji de {$name} deve ser string");
            $this->assertNotEmpty($emoji, "emoji de {$name} não pode ser vazio");
        }
    }

    public function test_class_is_final_and_readonly(): void
    {
        // A classe deve ser final readonly — sem herança, sem mutação.
        $reflection = new \ReflectionClass(CategoryEmojiMap::class);
        $this->assertTrue($reflection->isFinal(), 'CategoryEmojiMap deve ser final');
        $this->assertTrue($reflection->isReadOnly(), 'CategoryEmojiMap deve ser readonly');
    }
}
