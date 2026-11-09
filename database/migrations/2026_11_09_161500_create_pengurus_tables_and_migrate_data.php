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
        // 1. Buat tabel pengurus_types
        Schema::create('pengurus_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('badge_color')->default('emerald');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        // 2. Buat tabel pivot generus_pengurus
        Schema::create('generus_pengurus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generus_id')->constrained('generus')->cascadeOnDelete();
            $table->foreignId('pengurus_type_id')->constrained('pengurus_types')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['generus_id', 'pengurus_type_id']);
        });

        // 3. Masukkan default master pengurus
        $now = now();
        $usmanId = DB::table('pengurus_types')->insertGetId([
            'name'        => 'Pengurus Usman',
            'code'        => 'PENGURUS_USMAN',
            'badge_color' => 'amber',
            'description' => 'Pengurus Usman / Usia Mandiri',
            'is_active'   => true,
            'order'       => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $mudaMudiId = DB::table('pengurus_types')->insertGetId([
            'name'        => 'Pengurus Muda Mudi',
            'code'        => 'PENGURUS_MUDA_MUDI',
            'badge_color' => 'blue',
            'description' => 'Pengurus Muda Mudi',
            'is_active'   => true,
            'order'       => 2,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $ketuaWakilId = DB::table('pengurus_types')->insertGetId([
            'name'        => 'Ketua/Wakil Kelompok',
            'code'        => 'KETUA_WAKIL_KELOMPOK',
            'badge_color' => 'emerald',
            'description' => 'Ketua atau Wakil Kelompok',
            'is_active'   => true,
            'order'       => 3,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // 4. Migrasikan data pengurus yang sudah ada dari kolom boolean generus
        $usmanGenerus = DB::table('generus')->where('is_pengurus', true)->pluck('id');
        foreach ($usmanGenerus as $gid) {
            DB::table('generus_pengurus')->insertOrIgnore([
                'generus_id'       => $gid,
                'pengurus_type_id' => $usmanId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        $mudaMudiGenerus = DB::table('generus')->where('is_pengurus_muda_mudi', true)->pluck('id');
        foreach ($mudaMudiGenerus as $gid) {
            DB::table('generus_pengurus')->insertOrIgnore([
                'generus_id'       => $gid,
                'pengurus_type_id' => $mudaMudiId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        $ketuaWakilGenerus = DB::table('generus')->where('is_ketua_wakil_kelompok', true)->pluck('id');
        foreach ($ketuaWakilGenerus as $gid) {
            DB::table('generus_pengurus')->insertOrIgnore([
                'generus_id'       => $gid,
                'pengurus_type_id' => $ketuaWakilId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generus_pengurus');
        Schema::dropIfExists('pengurus_types');
    }
};
