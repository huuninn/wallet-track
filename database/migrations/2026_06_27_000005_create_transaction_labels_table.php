<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_labels', function (Blueprint $table) {
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->primary(['transaction_id', 'label_id']);
            $table->index('label_id', 'transaction_labels_label_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_labels');
    }
};
