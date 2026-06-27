<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('folded_name', 100)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at', 3)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
