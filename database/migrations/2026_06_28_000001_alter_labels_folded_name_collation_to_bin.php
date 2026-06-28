<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labels', function (Blueprint $table) {
            $table->string('folded_name', 100)->charset('utf8mb4')->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('labels', function (Blueprint $table) {
            $table->string('folded_name', 100)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
