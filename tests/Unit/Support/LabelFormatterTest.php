<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LabelFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários do {@see LabelFormatter} (regras P1 e P7).
 *
 * Cobre todos os edge cases da spec: palavras comuns, acentos, ALL CAPS,
 * multi-palavras, marcas, acrônimos, idempotência, vazio, whitespace,
 * emoji e truncamento via MAX_LENGTH.
 *
 * Teste puro (estende PHPUnit\Framework\TestCase), sem container Laravel.
 *
 * Roda isoladamente: bin/dev test --filter LabelFormatterTest
 */
#[CoversClass(LabelFormatter::class)]
class LabelFormatterTest extends TestCase
{
    public function test_simple_word_is_capitalized(): void
    {
        $this->assertSame('Almoco', LabelFormatter::format('almoco'));
    }

    public function test_accent_is_preserved(): void
    {
        $this->assertSame('Café', LabelFormatter::format('café'));
    }

    public function test_accent_in_middle_is_preserved(): void
    {
        $this->assertSame('Coração', LabelFormatter::format('coração'));
    }

    public function test_all_caps_is_normalized_to_sentence_case(): void
    {
        $this->assertSame('Almoço', LabelFormatter::format('ALMOÇO'));
    }

    public function test_multi_word_is_sentence_case_not_title_case(): void
    {
        $this->assertSame('Casa da praia', LabelFormatter::format('casa da praia'));
    }

    public function test_brand_with_internal_caps_is_preserved(): void
    {
        $this->assertSame('iFood', LabelFormatter::format('iFood'));
    }

    public function test_acronym_all_caps_is_preserved(): void
    {
        $this->assertSame('PIX', LabelFormatter::format('PIX'));
    }

    public function test_lowercase_not_brand_applies_p1(): void
    {
        $this->assertSame('Ifood', LabelFormatter::format('ifood'));
    }

    public function test_lowercase_pix_applies_p1(): void
    {
        $this->assertSame('Pix', LabelFormatter::format('pix'));
    }

    public function test_iphone_brand_is_preserved(): void
    {
        $this->assertSame('iPhone', LabelFormatter::format('iPhone'));
    }

    public function test_is_idempotent(): void
    {
        $first = LabelFormatter::format('Almoço');
        $second = LabelFormatter::format($first);

        $this->assertSame('Almoço', $first);
        $this->assertSame($first, $second);
        $this->assertSame(LabelFormatter::format(LabelFormatter::format('Almoço')), LabelFormatter::format('Almoço'));
    }

    public function test_idempotent_on_already_formatted(): void
    {
        $this->assertSame(
            LabelFormatter::format('Casa da praia'),
            LabelFormatter::format(LabelFormatter::format('Casa da praia')),
        );
    }

    public function test_idempotent_on_brand(): void
    {
        $this->assertSame(
            LabelFormatter::format('iFood'),
            LabelFormatter::format(LabelFormatter::format('iFood')),
        );
    }

    public function test_idempotent_on_acronym(): void
    {
        $this->assertSame(
            LabelFormatter::format('PIX'),
            LabelFormatter::format(LabelFormatter::format('PIX')),
        );
    }

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', LabelFormatter::format(''));
    }

    public function test_whitespace_only_returns_empty(): void
    {
        $this->assertSame('', LabelFormatter::format('  '));
    }

    public function test_emoji_is_preserved(): void
    {
        $this->assertSame('Pizza 🍕', LabelFormatter::format('pizza 🍕'));
    }

    public function test_max_length_truncates_with_ellipsis(): void
    {
        // Cria uma string de 50 caracteres minúsculos.
        $long = str_repeat('a', 50);
        $result = LabelFormatter::format($long);

        // Após formatação P1: primeira letra vira 'A' + 49 'a's = 50 chars.
        // Trunca para MAX_LENGTH=40: 37 chars + "..." = 40 chars.
        $this->assertSame(40, mb_strlen($result));
        $this->assertStringEndsWith('...', $result);
        $this->assertStringStartsWith('A', $result);
    }

    public function test_idempotent_casa_da_praia(): void
    {
        $once = LabelFormatter::format('casa da praia');
        $twice = LabelFormatter::format($once);

        $this->assertSame('Casa da praia', $once);
        $this->assertSame($once, $twice);
    }

    public function test_idempotent_all_caps(): void
    {
        $once = LabelFormatter::format('ALMOÇO');
        $twice = LabelFormatter::format($once);

        $this->assertSame('Almoço', $once);
        $this->assertSame('Almoço', $twice);
    }
}
