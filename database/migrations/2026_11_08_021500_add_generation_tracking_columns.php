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
        // Tanggal terakhir jadwal ini membuat acara. Mencegah acara dibuat ulang
        // setelah operator menghapusnya di hari yang sama.
        Schema::table('group_schedules', function (Blueprint $table) {
            $table->date('last_generated_date')->nullable()->after('is_active');
        });

        // Penanda acara sudah pernah ditutup otomatis. Mencegah acara yang dibuka
        // kembali secara manual langsung ditutup lagi oleh scheduler.
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('auto_closed_at')->nullable()->after('is_closed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_schedules', function (Blueprint $table) {
            $table->dropColumn('last_generated_date');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('auto_closed_at');
        });
    }
};
