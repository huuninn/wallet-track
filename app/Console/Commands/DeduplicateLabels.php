<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Store\WalletStore;
use App\Support\TextNormalizer;
use Illuminate\Console\Command;

/**
 * Deduplica labels no banco usando {@see TextNormalizer::fold()}.
 *
 * Após a migração para folding (F1), labels previamente criadas com
 * acentuação/capitalização diferentes coexistem como documentos
 * separados (ex.: "Almoço", "almoco", "ALMOCO"). Este comando
 * consolida os duplicados em um único documento canônico e remove
 * os redundantes.
 *
 * Regras:
 *  - Agrupa por {@see TextNormalizer::fold($name)}.
 *  - Para grupos com count > 1: soma `use_count`, pega `max(last_used_at)`,
 *    nome canônico = folded name. Cria doc folded + deleta duplicados.
 *  - Para grupos com count == 1 mas `id !== fold(name)`: renomeia
 *    (cria folded + deleta antigo).
 *  - Para grupos com count == 1 e `id === fold(name)`: inalterado.
 *
 * ISOLAMENTO: opera SOMENTE na tabela `labels`. A tabela `categories` NUNCA
 * é referenciada — categorias preservam acentos via `normalizeName()`.
 *
 * Uso:
 *   php artisan labels:deduplicate           # executa (com confirmação)
 *   php artisan labels:deduplicate --dry-run # preview sem alterar
 */
final class DeduplicateLabels extends Command
{
    protected $signature = 'labels:deduplicate {--dry-run : Exibe o que seria feito, sem alterar}';

    protected $description = 'Deduplica labels no banco (folding acento-insensível)';

    public function handle(): int
    {
        /** @var WalletStore $store */
        $store = app(WalletStore::class);

        $docs = $store->listAllLabels();

        // Agrupa por fold(name).
        $groups = [];
        foreach ($docs as $doc) {
            $name = $doc->name;
            $key = TextNormalizer::fold($name);
            if ($key === '') {
                continue;
            }
            $groups[$key][] = $doc;
        }

        $isDryRun = (bool) $this->option('dry-run');

        /*
         |--------------------------------------------------------------------------
         | Fase 1 — Coleta de ações (somente leitura; NÃO escreve no banco de dados)
         |--------------------------------------------------------------------------
         | A separação leitura/escrita em duas fases é essencial para que a
         | confirmação interativa funcione corretamente: se as mutações fossem
         | aplicadas no loop de coleta, o `$this->confirm()` (fase 2) ocorreria
         | DEPOIS das mudanças — se o usuário respondesse "no", os dados já
         | estariam alterados e o comando exibiria "Operação cancelada."
         | falsamente.
         */
        $actions = [];

        foreach ($groups as $canonicalKey => $group) {
            if (count($group) > 1) {
                // Grupo com duplicatas — consolida.
                $useCount = 0;
                $lastUsedAt = null;

                foreach ($group as $doc) {
                    $useCount += (int) ($doc->use_count ?? 0);
                    $at = $doc->last_used_at ?? null;
                    if ($at !== null && ($lastUsedAt === null || $at > $lastUsedAt)) {
                        $lastUsedAt = $at;
                    }
                }

                // Mantém o nome do primeiro doc como display name.
                $displayName = $group[0]->name;

                $actions[] = [
                    'id_atual' => implode(', ', array_map(fn ($d) => $d->folded_name, $group)),
                    'id_canonico' => $canonicalKey,
                    'use_count_somado' => $useCount,
                    'acao' => 'consolidar',
                    // Metadados para replay da mutação na fase 2.
                    'display_name' => $displayName,
                    'last_used_at' => $lastUsedAt,
                    'ids' => array_map(fn ($d) => $d->folded_name, $group),
                ];
            } elseif (count($group) === 1 && $group[0]->folded_name !== $canonicalKey) {
                // Documento único, mas id não é o folded — renomeia.
                $doc = $group[0];
                $useCount = (int) ($doc->use_count ?? 0);
                $lastUsedAt = $doc->last_used_at ?? null;
                $displayName = $doc->name;

                $actions[] = [
                    'id_atual' => $doc->folded_name,
                    'id_canonico' => $canonicalKey,
                    'use_count_somado' => $useCount,
                    'acao' => 'renomear',
                    // Metadados para replay da mutação na fase 2.
                    'display_name' => $displayName,
                    'last_used_at' => $lastUsedAt,
                    'ids' => [$doc->folded_name],
                ];
            }
            // count == 1 && id == key → inalterado, sem ação.
        }

        if ($actions === []) {
            $this->info('Nenhuma duplicata encontrada — labels já estão deduplicadas.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->info(sprintf('DRY-RUN: %d ação(ões) seriam executadas.', count($actions)));
            $this->table(
                ['ID Atual', 'ID Canônico', 'Use Count', 'Ação'],
                array_map(static fn (array $a): array => [
                    $a['id_atual'],
                    $a['id_canonico'],
                    (string) $a['use_count_somado'],
                    $a['acao'],
                ], $actions),
            );

            return self::SUCCESS;
        }

        /*
         |--------------------------------------------------------------------------
         | Fase 2 — Confirmação + Execução (mutação no banco de dados)
         |--------------------------------------------------------------------------
         | O `confirm()` PRECEDE a execução. Se o usuário responder "no",
         | nenhuma escrita ocorreu — o comando termina sem efeitos colaterais.
         */
        if (! $this->confirm(sprintf('%d ação(ões) de deduplicação serão executadas. Continuar?', count($actions)))) {
            $this->info('Operação cancelada.');

            return self::SUCCESS;
        }

        // Fase 3 — Aplicação: itera as ações coletadas e escreve no banco.
        foreach ($actions as $action) {
            // Cria (ou sobrescreve) o registro canônico.
            $store->upsertLabel($action['id_canonico'], [
                'name' => $action['display_name'],
                'use_count' => $action['use_count_somado'],
                'last_used_at' => $action['last_used_at'],
            ]);

            // Remove os ids antigos (exceto o próprio canônico, se coincidir).
            foreach ($action['ids'] as $oldFoldedName) {
                if ($oldFoldedName !== $action['id_canonico']) {
                    $store->deleteLabelByFoldedName($oldFoldedName);
                }
            }
        }

        $this->info(sprintf('%d ação(ões) concluída(s) com sucesso!', count($actions)));

        return self::SUCCESS;
    }
}
