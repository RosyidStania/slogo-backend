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
        Schema::table('events', function (Blueprint $table) {
            $table->time('end_time')->nullable()->after('start_time');
            $table->foreignId('group_schedule_id')->nullable()->after('event_type_id')->constrained('group_schedules')->nullOnDelete();
            $table->unique(['group_schedule_id', 'event_date'], 'events_schedule_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_schedule_date_unique');
            $table->dropConstrainedForeignId('group_schedule_id');
            $table->dropColumn('end_time');
        });
    }
};
