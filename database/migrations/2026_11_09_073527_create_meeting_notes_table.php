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
        Schema::create('meeting_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('judul');
            $table->date('tanggal');
            $table->string('waktu')->nullable();
            $table->string('lokasi')->nullable();
            $table->string('pemimpin')->nullable();
            $table->string('notulis')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('pembahasan')->nullable();
            $table->json('keputusan')->nullable();
            $table->integer('peserta_hadir_count')->nullable();
            $table->text('lampiran_info')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_notes');
    }
};
