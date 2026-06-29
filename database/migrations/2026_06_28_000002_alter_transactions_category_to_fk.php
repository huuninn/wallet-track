<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Substitui a coluna string `category` (VARCHAR 100) por `category_id`
     * (FK → categories.id). Estratégia: drop + recria — não preserva dados
     * existentes (ambiente dev; prod ainda não tem dados na coluna string).
     *
     * O índice `transactions_category_date_index` é recriado como
     * `transactions_category_id_date_index`.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // 1. Remove o índice que referencia a coluna string.
            $table->dropIndex('transactions_category_date_index');

            // 2. Remove a coluna string.
            $table->dropColumn('category');

            // 3. Adiciona a FK (nullable — transações podem não ter categoria).
            $table->unsignedBigInteger('category_id')->nullable()->after('type');

            // 4. Foreign key + índice individual para o FK lookup.
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete(); // SET NULL — não apaga transação ao apagar categoria

            // 5. Índice composto (substitui o antigo transactions_category_date_index).
            $table->index(['category_id', 'date'], 'transactions_category_id_date_index');
        });
    }

    /**
     * Reverte: drop FK + coluna `category_id`, recria coluna string `category`
     * e o índice original.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // 1. Remove o índice e a FK.
            $table->dropIndex('transactions_category_id_date_index');
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');

            // 2. Recria a coluna string original.
            $table->string('category', 100)->nullable()->after('type');

            // 3. Recria o índice original.
            $table->index(['category', 'date'], 'transactions_category_date_index');
        });
    }
};
