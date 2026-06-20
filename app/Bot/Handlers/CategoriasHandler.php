<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Services\Google\FirestoreService;
use App\Support\CategoryEmojiMap;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

/**
 * Handler do comando /categorias (M9.6 / T-007).
 *
 * Lista todas as categorias (padrão + personalizadas) com o respectivo
 * `use_count` (contador de uso acumulado). A listagem é ordenada por
 * `use_count DESC, display_name ASC` (spec §1.7) — categorias mais usadas
 * aparecem primeiro; empates são desempatados alfabeticamente.
 *
 * Decisão do Portão 2 #4: o `use_count` é ESCOPO GLOBAL (não particionado
 * por chat). Isso é o que o schema atual da coleção `categories/` produz —
 * o handler apenas lê e formata, sem agregar por chat.
 *
 * O handler é **stateless puro** — não lê nem escreve sessão. Pode ser
 * invocado em qualquer estado da máquina conversacional sem afetar a
 * transação em andamento (CT-029f, Portão 2 #3).
 *
 * O mapeamento de emojis por categoria é fixo (spec §6.3) e vive em
 * {@see CategoryEmojiMap} (single source of truth compartilhada com o
 * {@see TransactionSummaryFormatter}). Categorias
 * personalizadas (fora do mapa canônico) usam o fallback local `🏷` —
 * sinaliza visualmente que aquela categoria não está nas 9 padrão.
 *
 * Ref.: docs/specs/m9-spec-fase-2.md §2.3, docs/planos/m9-plano-tecnico.md (T-007).
 */
final class CategoriasHandler
{
    /**
     * Fallback visual para categorias fora do mapa canônico. Diferente do
     * `📦` retornado por {@see CategoryEmojiMap::get()} — aqui usamos
     * `🏷` para sinalizar "categoria personalizada" (não-padrão).
     */
    private const string CATEGORY_EMOJI_FALLBACK = '🏷';

    /**
     * Invoca o handler: lista todas as categorias (padrão + personalizadas)
     * ordenadas por `use_count DESC` (CT-029). Stateless — não mexe na
     * sessão (CT-029f).
     *
     * @param  Nutgram  $bot  Instância do bot injetada pelo BotLoader.
     */
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();
        if ($message === null) {
            return;
        }

        $chatId = (string) (int) $message->chat->id;

        try {
            $firestore = app(FirestoreService::class);
            $messenger = app(BotMessenger::class);

            $categories = $firestore->getCategories();

            $messenger->sendText($chatId, $this->renderList($categories));
        } catch (\Throwable $e) {
            Log::error('CategoriasHandler falhou', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            app(BotMessenger::class)->notifyError(
                $chatId,
                'Não consegui listar suas categorias agora. Tente novamente em alguns instantes.',
            );
        }
    }

    /**
     * Formata a listagem de categorias (PT-BR, HTML).
     *
     * @param  list<array{id: string, data: array<string, mixed>}>  $categories
     */
    private function renderList(array $categories): string
    {
        // Ordena por use_count DESC, depois display_name ASC.
        $sorted = $categories;
        usort(
            $sorted,
            fn (array $a, array $b): int => (($b['data']['use_count'] ?? 0) <=> ($a['data']['use_count'] ?? 0))
                ?: strcmp((string) ($a['data']['display_name'] ?? ''), (string) ($b['data']['display_name'] ?? ''))
        );

        $lines = ['📊 <b>Categorias</b>', ''];

        if ($sorted === []) {
            $lines[] = '<i>Nenhuma categoria cadastrada ainda. Crie ao registrar transações — elas aparecerão aqui automaticamente.</i>';
        } else {
            foreach ($sorted as $row) {
                $data = $row['data'];
                $name = (string) ($data['display_name'] ?? '?');
                $count = (int) ($data['use_count'] ?? 0);
                $emoji = CategoryEmojiMap::EMOJIS[$name] ?? self::CATEGORY_EMOJI_FALLBACK;
                $noun = $count === 1 ? 'transação' : 'transações';
                $lines[] = "{$emoji} {$name} — {$count} {$noun}";
            }

            $lines[] = '';
            $lines[] = '✨ <i>Crie novas categorias ao registrar transações — elas aparecerão aqui automaticamente.</i>';
        }

        return implode("\n", $lines);
    }
}
