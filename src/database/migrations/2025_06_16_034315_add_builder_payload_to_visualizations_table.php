<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('visualizations', function (Blueprint $table) {
            // Menambahkan kolom builder_payload dengan tipe JSON, bisa null,
            // dan ditempatkan setelah kolom 'config' agar rapi.
            $table->json('builder_payload')->nullable()->after('config');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visualizations', function (Blueprint $table) {
            // Menghapus kolom jika migrasi di-rollback
            $table->dropColumn('builder_payload');
        });
    }
};