<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('name');
            $table->text('target_kategori')->nullable()->after('start_time'); // Menyimpan array kategori peserta dalam bentuk JSON/Teks
        });
    }

    public function down()
    {
        Schema::table('event_types', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'target_kategori']);
        });
    }
};
