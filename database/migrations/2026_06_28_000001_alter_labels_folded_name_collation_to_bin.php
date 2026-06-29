<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite não suporta collation utf8mb4_bin — apenas MySQL/MariaDB.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('labels', function (Blueprint $table) {
            $table->string('folded_name', 100)->charset('utf8mb4')->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('labels', function (Blueprint $table) {
            $table->string('folded_name', 100)->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
