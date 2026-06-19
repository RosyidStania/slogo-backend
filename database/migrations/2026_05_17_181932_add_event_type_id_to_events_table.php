<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('events', function (Blueprint $table) {
            // Menambahkan kolom event_type_id
            $table->foreignId('event_type_id')->nullable()->after('id')->constrained('event_types')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['event_type_id']);
            $table->dropColumn('event_type_id');
        });
    }
};