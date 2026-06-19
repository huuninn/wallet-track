<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\WizardStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Conversation\WizardHandlerTest;

/**
 * Testes unitários do enum {@see WizardStep} (M9.3 / T-018).
 *
 * Cobre o mapeamento entre cases e o nome do campo no DTO, a sequência
 * `next()` e a label humana (PT-BR). Não cobre integração — a sequência
 * em si é testada em {@see WizardHandlerTest}.
 *
 * Roda isoladamente: vendor/bin/phpunit --filter WizardStepTest
 */
#[CoversClass(WizardStep::class)]
class WizardStepTest extends TestCase
{
    public function test_values_are_sequential_starting_at_one(): void
    {
        // Os values devem ser 1, 2, 3, 4, 5, 6 (sem gaps) — qualquer
        // mudança aqui exige revisão de Firestore (campo _wizard_step).
        $this->assertSame(1, WizardStep::TYPE->value);
        $this->assertSame(2, WizardStep::AMOUNT->value);
        $this->assertSame(3, WizardStep::DESCRIPTION->value);
        $this->assertSame(4, WizardStep::CATEGORY->value);
        $this->assertSame(5, WizardStep::LABELS->value);
        $this->assertSame(6, WizardStep::CONFIRMATION->value);
    }

    public function test_field_name_maps_to_dto_property(): void
    {
        $this->assertSame('type', WizardStep::TYPE->fieldName());
        $this->assertSame('amount', WizardStep::AMOUNT->fieldName());
        $this->assertSame('description', WizardStep::DESCRIPTION->fieldName());
        $this->assertSame('category', WizardStep::CATEGORY->fieldName());
        $this->assertSame('labels', WizardStep::LABELS->fieldName());
        $this->assertSame('', WizardStep::CONFIRMATION->fieldName());
    }

    public function test_next_advances_in_sequence(): void
    {
        $this->assertSame(WizardStep::AMOUNT, WizardStep::TYPE->next());
        $this->assertSame(WizardStep::DESCRIPTION, WizardStep::AMOUNT->next());
        $this->assertSame(WizardStep::CATEGORY, WizardStep::DESCRIPTION->next());
        $this->assertSame(WizardStep::LABELS, WizardStep::CATEGORY->next());
        $this->assertSame(WizardStep::CONFIRMATION, WizardStep::LABELS->next());
    }

    public function test_next_returns_null_at_confirmation(): void
    {
        // CONFIRMATION é o fim do wizard — não há "próximo" porque o
        // Router assume o controle e chama presentConfirmation.
        $this->assertNull(WizardStep::CONFIRMATION->next());
    }

    public function test_label_is_pt_br(): void
    {
        $this->assertSame('Tipo', WizardStep::TYPE->label());
        $this->assertSame('Valor', WizardStep::AMOUNT->label());
        $this->assertSame('Descrição', WizardStep::DESCRIPTION->label());
        $this->assertSame('Categoria', WizardStep::CATEGORY->label());
        $this->assertSame('Labels', WizardStep::LABELS->label());
        $this->assertSame('Confirmação', WizardStep::CONFIRMATION->label());
    }

    public function test_total_asked_steps_constant(): void
    {
        // Confirmação não conta (não é pergunta, é tela de revisão).
        $this->assertSame(5, WizardStep::TOTAL_ASKED_STEPS);
    }

    public function test_from_value_round_trip(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $step = WizardStep::from($i);
            $this->assertSame($i, $step->value);
        }
    }
}
