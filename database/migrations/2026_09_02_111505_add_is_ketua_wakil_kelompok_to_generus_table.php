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
        Schema::table('generus', function (Blueprint $table) {
            $table->boolean('is_ketua_wakil_kelompok')->default(false)->after('is_pengurus_muda_mudi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generus', function (Blueprint $table) {
            $table->dropColumn('is_ketua_wakil_kelompok');
        });
    }
};
