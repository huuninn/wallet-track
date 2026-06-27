<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('name', 500);
            $table->decimal('qty', 10, 3)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();

            // FK constrained() já cria índice implícito em transaction_id.
            // Índice explícito seria redundante (custo extra de disco/escrita).
            $table->unique(['transaction_id', 'position'], 'transaction_items_tx_pos_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
