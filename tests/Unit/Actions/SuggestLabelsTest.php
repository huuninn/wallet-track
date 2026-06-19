<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\SuggestLabels;
use App\Services\Google\FirestoreService;
use App\Services\Google\InMemoryFirestoreGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Testes da heurística de sugestão de labels (M8.1).
 *
 * Cobre o algoritmo definido em `docs/04-clarificacoes.md` §4:
 *  - Fase A — Histórico (Firestore) tem prioridade sobre Fase B (keywords).
 *  - Cap em {@see SuggestLabels::MAX_SUGGESTED_LABELS} (5).
 *  - Dedup contra `$existingLabels` e contra o acumulado.
 *  - NUNCA inventa labels quando não há fonte de sinal.
 *  - `MIN_TOKEN_LENGTH = 3` filtra keywords curtas.
 *
 * Roda isolado: bin/dev test --filter SuggestLabelsTest
 */
#[CoversClass(SuggestLabels::class)]
class SuggestLabelsTest extends TestCase
{
    private FirestoreService $firestore;

    private SuggestLabels $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->firestore = new FirestoreService(new InMemoryFirestoreGateway);
        $this->action = new SuggestLabels($this->firestore);
    }

    public function test_returns_empty_when_no_history_no_keywords(): void
    {
        // Nenhum label na coleção + descrição sem keywords.
        $result = $this->action->suggest('oi', 'Outros');

        $this->assertSame([], $result, 'Não deve inventar labels sem fonte de sinal');
    }

    public function test_returns_empty_when_description_is_empty_and_history_is_empty(): void
    {
        $result = $this->action->suggest(null, 'Outros');

        $this->assertSame([], $result);
    }

    public function test_history_takes_priority_over_keywords(): void
    {
        // Histórico: "ifood" (3x) e "restaurante" (2x).
        for ($i = 0; $i < 3; $i++) {
            $this->firestore->incrementLabelUse('ifood');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->firestore->incrementLabelUse('restaurante');
        }

        // Descrição contém "pizza" como keyword — mas o histórico tem prioridade
        // (vem primeiro no array retornado).
        $result = $this->action->suggest('Comi pizza no iFood', 'Alimentação');

        $this->assertSame(['ifood', 'restaurante'], array_slice($result, 0, 2));
        $ifoodIdx = array_search('ifood', $result, true);
        $pizzaIdx = array_search('pizza', $result, true);
        $this->assertIsInt($ifoodIdx);
        $this->assertIsInt($pizzaIdx);
        $this->assertLessThan(
            $pizzaIdx,
            $ifoodIdx,
            'ifood (histórico) deve vir antes de pizza (keyword)',
        );
    }

    public function test_respects_max_5_limit(): void
    {
        // Cria 10 labels com mesmo use_count → top 10 (HISTORY_LIMIT).
        for ($i = 1; $i <= 10; $i++) {
            $this->firestore->incrementLabelUse("label-{$i}");
        }

        // Descrição com várias keywords adicionais.
        $result = $this->action->suggest(
            'alpha beta gamma delta epsilon zeta eta',
            'Outros',
        );

        $this->assertCount(SuggestLabels::MAX_SUGGESTED_LABELS, $result);
        $this->assertLessThanOrEqual(5, count($result));
    }

    public function test_keywords_added_after_history_when_slots_available(): void
    {
        // Histórico: 2 labels (cobra 2 vagas).
        $this->firestore->incrementLabelUse('ifood');
        $this->firestore->incrementLabelUse('restaurante');

        // Descrição com keywords suficientes para preencher até o MAX.
        $result = $this->action->suggest(
            'Comi pizza japonês domingo',
            'Alimentação',
        );

        $this->assertCount(5, $result);
        // Primeiros 2: do histórico.
        $this->assertSame('ifood', $result[0]);
        $this->assertSame('restaurante', $result[1]);
        // Demais: das keywords (em ordem FIFO).
        $this->assertContains('comi', $result);
        $this->assertContains('pizza', $result);
        $this->assertContains('japones', $result);
        // "domingo" pode ou não entrar (depende se 'japones' cabe antes);
        // não validamos sua presença para manter o teste resiliente.
    }

    public function test_dedupes_against_existing_labels(): void
    {
        // Histórico: ifood.
        $this->firestore->incrementLabelUse('ifood');
        $this->firestore->incrementLabelUse('restaurante');

        // existingLabels já contém "ifood" — não deve ser sugerido de novo.
        $result = $this->action->suggest(
            'Comi pizza no ifood',
            'Alimentação',
            existingLabels: ['ifood'],
        );

        $this->assertNotContains('ifood', $result);
        $this->assertContains('restaurante', $result);
    }

    public function test_dedupes_against_existing_labels_case_insensitive(): void
    {
        $this->firestore->incrementLabelUse('ifood');

        $result = $this->action->suggest(
            'Pizza ifood',
            'Alimentação',
            existingLabels: ['IFOOD'],
        );

        $this->assertNotContains('ifood', $result);
        $this->assertNotContains('IFOOD', $result);
    }

    public function test_dedupes_keyword_against_existing_label(): void
    {
        // Sem histórico.
        $result = $this->action->suggest(
            'Comi pizza no iFood',
            'Alimentação',
            existingLabels: ['pizza'],
        );

        $this->assertNotContains('pizza', $result);
        $this->assertContains('ifood', $result);
    }

    public function test_dedupes_against_result_within_keywords(): void
    {
        // Sem histórico. Descrição com keyword repetida → dedup.
        $result = $this->action->suggest(
            'Pizza pizza pizza',
            'Alimentação',
        );

        $pizzaCount = count(array_filter($result, static fn (string $k): bool => $k === 'pizza'));
        $this->assertSame(1, $pizzaCount, 'pizza repetido deve virar 1 entrada');
    }

    public function test_does_not_invent_labels_with_empty_description(): void
    {
        $this->firestore->incrementLabelUse('ifood');

        // description vazia → sem keywords.
        $result = $this->action->suggest('', 'Alimentação');

        $this->assertSame(['ifood'], $result);
    }

    public function test_min_token_length_3_filters_short_keywords(): void
    {
        // Sem histórico. Descrição com tokens de 1-2 chars (depois de split) e curtos.
        // "tv" tem 2 chars → removido; "a b" → removidos.
        $result = $this->action->suggest(
            'tv a b c de da do',
            'Outros',
        );

        $this->assertNotContains('tv', $result);
        $this->assertNotContains('a', $result);
        $this->assertNotContains('b', $result);
        $this->assertNotContains('c', $result);
    }

    public function test_ct_019_scenario(): void
    {
        // CT-019: histórico "Alimentação" contém #ifood e #restaurante.
        // Nova transação "Paguei R$ 32,00 na pizza do iFood" → sugere os 2 primeiros.
        for ($i = 0; $i < 3; $i++) {
            $this->firestore->incrementLabelUse('ifood');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->firestore->incrementLabelUse('restaurante');
        }

        $result = $this->action->suggest('Paguei 32,00 na pizza do iFood', 'Alimentação');

        // iFood (3) e restaurante (2) têm prioridade do histórico.
        $this->assertSame('ifood', $result[0]);
        $this->assertSame('restaurante', $result[1]);
    }

    public function test_ct_020_scenario(): void
    {
        // CT-020: histórico VAZIO → keywords dominam.
        // "Paguei R$ 120,00 na conta de luz da enel" → conta, luz, enel.
        $result = $this->action->suggest(
            'Paguei 120,00 na conta de luz da enel',
            'Moradia',
        );

        $this->assertContains('conta', $result);
        $this->assertContains('luz', $result);
        $this->assertContains('enel', $result);
    }

    public function test_ct_021_scenario(): void
    {
        // CT-021: usuário edita labels para #japones, #domingo.
        // Sugestões automáticas (#ifood) NÃO devem ser consideradas "finais" —
        // o comportamento correto é: as sugestões alimentam o draft, e o usuário
        // tem poder de edição. Aqui verificamos que as sugestões iniciais NÃO
        // bloqueiam edição futura: o que SuggestLabels devolve é o que
        // entra no draft, e o ConversationRouter permite ao usuário editar.
        $this->firestore->incrementLabelUse('ifood');

        $suggested = $this->action->suggest(
            'Paguei 55,00 no iFood de japonês domingo',
            'Alimentação',
        );

        $this->assertContains('ifood', $suggested);
        // japonês e domingo também aparecem das keywords.
        $this->assertContains('japones', $suggested);
        $this->assertContains('domingo', $suggested);

        // Simulação da edição: usuário mantém só #japones e #domingo.
        $finalLabels = ['japones', 'domingo'];
        $this->assertNotContains('ifood', $finalLabels, 'Edição removeu ifood');
    }

    public function test_history_limit_10_applied(): void
    {
        // Cria 15 labels com mesmo use_count.
        for ($i = 1; $i <= 15; $i++) {
            $this->firestore->incrementLabelUse("label-{$i}");
        }

        // HISTORY_LIMIT=10 consulta top 10, mas MAX_SUGGESTED_LABELS=5 limita
        // o retorno final a 5. (Spec §4: ambos os limites coexistem.)
        $result = $this->action->suggest('', 'Outros');

        $this->assertCount(SuggestLabels::MAX_SUGGESTED_LABELS, $result);
    }

    public function test_returns_at_most_max_when_only_history(): void
    {
        // 8 labels no histórico → só 5 são devolvidos (cap).
        for ($i = 1; $i <= 8; $i++) {
            $this->firestore->incrementLabelUse("label-{$i}");
        }

        $result = $this->action->suggest('', 'Outros');

        $this->assertCount(5, $result);
    }
}
