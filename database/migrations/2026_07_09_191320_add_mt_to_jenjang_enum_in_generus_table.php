<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE generus MODIFY jenjang ENUM(
            'PAUD', 'TK', 
            '1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD', 
            '1 SMP', '2 SMP', '3 SMP', 
            '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK', 
            'USMAN', 'MT'
        ) DEFAULT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generus', function (Blueprint $table) {
            //
        });
    }
};
