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
        Schema::create('generus', function (Blueprint $table) {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('kelompok')->nullable(); // Slogo, Karangasem, dll
            $table->enum('status', ['aktif', 'tidak aktif', 'pasif'])->default('aktif');
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->integer('umur')->nullable();
            $table->enum('jenis_kelamin', ['L', 'P'])->default('L');
            $table->string('jenjang')->nullable(); // Pengganti nama kategori (USMAN, 6 SD, TK)
            $table->string('keterangan')->nullable();
            $table->string('libur')->nullable();
            $table->string('nama_ayah')->nullable(); // Mengganti nama_bapak
            $table->string('nama_ibu')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('akun_media')->nullable();
            $table->string('hobi')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generuses');
    }
};
