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
        Schema::create('group_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('kelompok');
            $table->unsignedTinyInteger('day_of_week'); // 0 = Minggu, 1 = Senin, 2 = Selasa (Mlm Rabu), 3 = Rabu (Mlm Kamis), 4 = Kamis, 5 = Jumat (Mlm Sabtu), 6 = Sabtu
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->string('name');
            $table->text('target_kategori')->nullable();
            $table->boolean('allow_other_participants')->default(false);
            $table->unsignedSmallInteger('lead_minutes')->default(30);
            $table->unsignedSmallInteger('auto_close_minutes')->default(120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_schedules');
    }
};
