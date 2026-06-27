<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // Telegram chat IDs podem ser strings de até 14 caracteres
            // (ex.: "-1001234567890" para supergrupos). VARCHAR(32) com
            // folga para o futuro (IDs como string de int64).
            $table->string('chat_id', 32);
            $table->date('date');
            $table->string('description', 500);
            $table->decimal('amount', 12, 2);
            $table->string('type', 7); // 'expense' | 'income'
            $table->string('category', 100)->nullable();
            $table->text('observations')->nullable();
            $table->string('sync_status', 10)->default('pending'); // pending|synced|failed
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->timestamp('sync_last_attempt_at', 3)->nullable();
            $table->text('sync_error_message')->nullable();
            $table->string('spreadsheet_row_id', 64)->nullable();
            $table->boolean('processing')->default(false);
            $table->timestamp('processing_since', 3)->nullable();
            $table->timestamp('notified_at', 3)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            // useCurrentOnUpdate(): MariaDB nativo (ON UPDATE CURRENT_TIMESTAMP);
            // SQLite via trigger (Laravel 11+ SQLiteGrammar). Compatível com
            // ambos os drivers — essencial para testes (phpunit.xml usa sqlite).
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['chat_id', 'date'], 'transactions_chat_id_date_index');
            $table->index(['chat_id', 'type', 'date'], 'transactions_chat_id_type_date_index');
            $table->index(['sync_status', 'created_at'], 'transactions_sync_status_created_at_index');
            $table->index(['sync_status', 'chat_id', 'created_at'], 'transactions_sync_status_chat_id_created_at_index');
            $table->index(['type', 'date'], 'transactions_type_date_index');
            $table->index(['category', 'date'], 'transactions_category_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
