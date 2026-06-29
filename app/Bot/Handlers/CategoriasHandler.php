<?php

declare(strict_types=1);

namespace App\Bot\Handlers;

use App\Bot\Messaging\BotMessenger;
use App\Bot\Messaging\TransactionSummaryFormatter;
use App\Services\Store\WalletStore;
use App\Support\CategoryEmojiMap;
use Illuminate\Database\Eloquent\Collection;
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

        // S-3: service location padronizado para `$services = app(); $services->make(...)` —
        // consistente com SyncHandler, UltimosHandler, CancelarHandler e NovaHandler.
        // S-2: $messenger resolvido UMA ÚNICA VEZ, antes do try — assim o catch block
        // reusa a mesma instância em vez de re-resolver via `app(BotMessenger::class)`.
        $services = app();
        $store = $services->make(WalletStore::class);
        $messenger = $services->make(BotMessenger::class);

        try {
            $categories = $store->getCategories();

            $messenger->sendText($chatId, $this->renderList($categories));
        } catch (\Throwable $e) {
            Log::error('CategoriasHandler falhou', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $messenger->notifyError(
                $chatId,
                'Não consegui listar suas categorias agora. Tente novamente em alguns instantes.',
            );
        }
    }

    /**
     * Formata a listagem de categorias (PT-BR, HTML).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\Category>  $categories
     */
    private function renderList(Collection $categories): string
    {
        // Ordena por use_count DESC, depois display_name ASC.
        $sorted = $categories->sortBy([
            ['use_count', 'desc'],
            ['display_name', 'asc'],
        ]);

        $lines = ['📊 <b>Categorias</b>', ''];

        if ($sorted->isEmpty()) {
            $lines[] = '<i>Nenhuma categoria cadastrada ainda. Crie ao registrar transações — elas aparecerão aqui automaticamente.</i>';
        } else {
            foreach ($sorted as $row) {
                $name = $row->display_name;
                $count = (int) $row->use_count;
                $emoji = CategoryEmojiMap::getEmoji($name, self::CATEGORY_EMOJI_FALLBACK);
                $noun = $count === 1 ? 'transação' : 'transações';
                $lines[] = "{$emoji} {$name} — {$count} {$noun}";
            }

            $lines[] = '';
            $lines[] = '✨ <i>Crie novas categorias ao registrar transações — elas aparecerão aqui automaticamente.</i>';
        }

        return implode("\n", $lines);
    }
}
