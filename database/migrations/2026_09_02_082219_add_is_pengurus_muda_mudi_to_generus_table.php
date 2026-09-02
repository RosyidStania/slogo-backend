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
            $table->boolean('is_pengurus_muda_mudi')->default(false)->after('is_pengurus');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generus', function (Blueprint $table) {
            $table->dropColumn('is_pengurus_muda_mudi');
        });
    }
};
