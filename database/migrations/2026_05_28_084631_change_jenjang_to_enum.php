<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Bersihkan spasi berlebih dan jadikan huruf besar semua
        DB::table('generus')->whereNotNull('jenjang')->update([
            'jenjang' => DB::raw('TRIM(UPPER(jenjang))')
        ]);

        // 2. Satukan tulisan yang bervariasi
        DB::table('generus')->whereIn('jenjang', ['1 SMA', '1 SMK'])->update(['jenjang' => '1 SMA/SMK']);
        DB::table('generus')->whereIn('jenjang', ['2 SMA', '2 SMK'])->update(['jenjang' => '2 SMA/SMK']);
        DB::table('generus')->whereIn('jenjang', ['3 SMA', '3 SMK'])->update(['jenjang' => '3 SMA/SMK']);
        
        // Gabungkan yang sudah lulus sekolah ke USMAN
        DB::table('generus')->whereIn('jenjang', ['MAHASISWA', 'KULIAH', 'KERJA', 'LULUS'])->update(['jenjang' => 'USMAN']);

        // 3. SAPU BERSIH: Jika masih ada data nyeleneh di luar daftar resmi, jadikan kosong (NULL)
        $daftarResmi = [
            'PAUD', 'TK', 
            '1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD', 
            '1 SMP', '2 SMP', '3 SMP', 
            '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK', 
            'USMAN'
        ];
        DB::table('generus')->whereNotIn('jenjang', $daftarResmi)->update(['jenjang' => null]);

        // 4. Setelah data 100% bersih, baru kunci tabelnya!
        DB::statement("ALTER TABLE generus MODIFY jenjang ENUM(
            'PAUD', 'TK', 
            '1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD', 
            '1 SMP', '2 SMP', '3 SMP', 
            '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK', 
            'USMAN'
        ) DEFAULT NULL");
    }

    public function down()
    {
        DB::statement("ALTER TABLE generus MODIFY jenjang VARCHAR(100) DEFAULT NULL");
    }
};