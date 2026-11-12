<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('role', 'users_role_index');
            $table->index('kelompok', 'users_kelompok_index');
        });

        Schema::table('generus', function (Blueprint $table) {
            $table->index('user_id', 'generus_user_id_index');
            $table->index('status', 'generus_status_index');
            $table->index('kelompok', 'generus_kelompok_index');
            $table->index('jenjang', 'generus_jenjang_index');
            $table->index(['status', 'kelompok'], 'generus_status_kelompok_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index('event_date', 'events_event_date_index');
            $table->index(['event_type_id', 'event_date'], 'events_event_type_date_index');
            $table->index('kelompok', 'events_kelompok_index');
            $table->index(['is_closed', 'event_date'], 'events_is_closed_date_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->index(['event_id', 'generus_id'], 'attendances_event_generus_index');
            $table->index(['event_id', 'status'], 'attendances_event_status_index');
            $table->index(['generus_id', 'status'], 'attendances_generus_status_index');
        });

        Schema::table('group_schedules', function (Blueprint $table) {
            $table->index('is_active', 'group_schedules_is_active_index');
        });

        Schema::table('meeting_notes', function (Blueprint $table) {
            $table->index('tanggal', 'meeting_notes_tanggal_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_index');
            $table->dropIndex('users_kelompok_index');
        });

        Schema::table('generus', function (Blueprint $table) {
            $table->dropIndex('generus_user_id_index');
            $table->dropIndex('generus_status_index');
            $table->dropIndex('generus_kelompok_index');
            $table->dropIndex('generus_jenjang_index');
            $table->dropIndex('generus_status_kelompok_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_event_date_index');
            $table->dropIndex('events_event_type_date_index');
            $table->dropIndex('events_kelompok_index');
            $table->dropIndex('events_is_closed_date_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_event_generus_index');
            $table->dropIndex('attendances_event_status_index');
            $table->dropIndex('attendances_generus_status_index');
        });

        Schema::table('group_schedules', function (Blueprint $table) {
            $table->dropIndex('group_schedules_is_active_index');
        });

        Schema::table('meeting_notes', function (Blueprint $table) {
            $table->dropIndex('meeting_notes_tanggal_index');
        });
    }
};
