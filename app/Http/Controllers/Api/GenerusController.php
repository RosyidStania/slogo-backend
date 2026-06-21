<?php

namespace App\Http\Controllers\Api;

use App\Models\Generus;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class GenerusController extends Controller
{
    public function index()
    {
        $generus = Generus::orderBy('created_at', 'desc')->get();
        return response()->json(['success' => true, 'data' => $generus], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_lengkap'  => 'required|string|max:255',
            'jenis_kelamin' => 'required|in:L,P',
            'kelompok'      => 'nullable|string|max:255',
            'jenjang'       => 'nullable|string|max:255',
            'status'        => 'required|in:aktif,tidak aktif,pasif', // nonaktif diubah jadi tidak aktif
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 422);
        }

        $userId = null;
        
        // Ambil nilai jenjang (jadikan huruf kecil semua untuk pengecekan)
        $jenjang = strtolower($request->jenjang ?? '');

        // LOGIKA BARU: Otomatis buat User untuk SEMUA jenjang (termasuk PAUD) agar ortu bisa memantau
        $isRemajaKeAtas = true; // Selalu true untuk membuat akun

        if ($isRemajaKeAtas) {
            $baseUsername = strtolower(str_replace(' ', '', $request->nama_lengkap));
            $username = $baseUsername;
            $counter = 1;

            while (User::where('username', $username)->exists()) {
                $username = $baseUsername . $counter;
                $counter++;
            }

            $user = User::create([
                'name'     => $request->nama_lengkap,
                'username' => $username,
                'password' => Hash::make($baseUsername . '123'), // Password: namatanpaspasi123
                'role'     => 'user',
            ]);
            $userId = $user->id;
        }

        $dataGenerus = $request->all();
        $dataGenerus['user_id'] = $userId;
        
        // Create Data Generus
        $generus = Generus::create($dataGenerus);

        return response()->json(['success' => true, 'message' => 'Data Generus berhasil ditambahkan', 'data' => $generus], 201);
    }

    public function update(Request $request, $id)
    {
        $generus = Generus::find($id);
        if (!$generus) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);

        $validator = Validator::make($request->all(), [
            'nama_lengkap'  => 'required|string|max:255',
            'jenis_kelamin' => 'required|in:L,P',
            'kelompok'      => 'nullable|string|max:255',
            'jenjang'       => 'nullable|string|max:255',
            'status'        => 'required|in:aktif,tidak aktif,pasif',
        ]);

        if ($validator->fails()) return response()->json(['success' => false, 'message' => $validator->errors()], 422);

        $generus->update($request->all());
        return response()->json(['success' => true, 'message' => 'Data Generus berhasil diupdate', 'data' => $generus], 200);
    }

    public function destroy($id)
    {
        $generus = Generus::find($id);
        if (!$generus) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);

        // Opsional: Hapus juga akun usernya jika ada
        if ($generus->user_id) {
            User::where('id', $generus->user_id)->delete();
        }

        $generus->delete();
        return response()->json(['success' => true, 'message' => 'Data berhasil dihapus'], 200);
    }
    public function import(Request $request)
    {
        // Hindari timeout jika data excel sangat banyak (hashing bcrypt memakan waktu)
        set_time_limit(0);

        $data = $request->json()->all();

        if (!is_array($data) || empty($data)) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid atau kosong'], 400);
        }

        try {
            // Ambil semua username yang ada untuk mencegah N+1 Query
            $existingUsernames = \App\Models\User::pluck('username')->flip()->toArray();

            $insertedCount = \Illuminate\Support\Facades\DB::transaction(function () use ($data, &$existingUsernames) {
                $count = 0;
                foreach ($data as $row) {
                    // Abaikan baris jika nama lengkap kosong
                    if (empty($row['nama_lengkap'])) continue;

                    $jenjang = strtolower($row['jenjang'] ?? '');
                    // LOGIKA BARU: Otomatis buat User untuk SEMUA jenjang agar ortu bisa memantau
                    $isRemajaKeAtas = true; 
                    $userId = null;

                    // Otomatis buat akun user jika usianya mencukupi
                    if ($isRemajaKeAtas) {
                        $baseUsername = strtolower(str_replace(' ', '', $row['nama_lengkap']));
                        $username = $baseUsername;
                        $counter = 1;
                        while (isset($existingUsernames[$username])) {
                            $username = $baseUsername . $counter;
                            $counter++;
                        }
                        $existingUsernames[$username] = true; // Tambahkan ke memori lokal

                        $user = \App\Models\User::create([
                            'name'     => $row['nama_lengkap'],
                            'username' => $username,
                            'password' => \Illuminate\Support\Facades\Hash::make($baseUsername . '123'),
                            'role'     => 'user',
                        ]);
                        $userId = $user->id;
                    }

                    // Normalisasi data Enum
                    $status = in_array(strtolower($row['status'] ?? ''), ['aktif', 'tidak aktif', 'pasif']) ? strtolower($row['status']) : 'aktif';
                    $jk = in_array(strtoupper($row['jenis_kelamin'] ?? ''), ['L', 'P']) ? strtoupper($row['jenis_kelamin']) : 'L';
                    
                    $rawJenjang = trim(strtoupper($row['jenjang'] ?? ''));
                    if (in_array($rawJenjang, ['1 SMA', '1 SMK'])) $rawJenjang = '1 SMA/SMK';
                    elseif (in_array($rawJenjang, ['2 SMA', '2 SMK'])) $rawJenjang = '2 SMA/SMK';
                    elseif (in_array($rawJenjang, ['3 SMA', '3 SMK'])) $rawJenjang = '3 SMA/SMK';
                    elseif (in_array($rawJenjang, ['MAHASISWA', 'KULIAH', 'KERJA', 'LULUS'])) $rawJenjang = 'USMAN';
                    
                    $daftarResmi = ['PAUD', 'TK', '1 SD', '2 SD', '3 SD', '4 SD', '5 SD', '6 SD', '1 SMP', '2 SMP', '3 SMP', '1 SMA/SMK', '2 SMA/SMK', '3 SMA/SMK', 'USMAN'];
                    $finalJenjang = in_array($rawJenjang, $daftarResmi) ? $rawJenjang : null;

                    \App\Models\Generus::create([
                        'nama_lengkap'  => $row['nama_lengkap'],
                        'kelompok'      => $row['kelompok'] ?: 'Slogo',
                        'status'        => $status,
                        'tempat_lahir'  => $row['tempat_lahir'] ?? null,
                        'tanggal_lahir' => $row['tanggal_lahir'] ?? null,
                        'umur'          => $row['umur'] ?? null,
                        'jenis_kelamin' => $jk,
                        'jenjang'       => $finalJenjang,
                        'keterangan'    => $row['keterangan'] ?? null,
                        'libur'         => $row['libur'] ?? null,
                        'nama_ayah'     => $row['nama_ayah'] ?? null,
                        'nama_ibu'      => $row['nama_ibu'] ?? null,
                        'no_hp'         => $row['no_hp'] ?? null,
                        'akun_media'    => $row['akun_media'] ?? null,
                        'hobi'          => $row['hobi'] ?? null,
                        'user_id'       => $userId,
                    ]);
                    
                    $count++;
                }
                return $count;
            });

            return response()->json(['success' => true, 'message' => "$insertedCount Data Excel berhasil diimport!"], 200);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Gagal mengimport data: ' . $e->getMessage()], 500);
        }
    }

    public function promoteAll()
    {
        // URUTAN PENTING: Harus dari jenjang tertinggi ke terendah agar tidak dobel naik
        $perubahan = [
            '3 SMA/SMK' => 'USMAN',
            '2 SMA/SMK' => '3 SMA/SMK',
            '1 SMA/SMK' => '2 SMA/SMK',
            '3 SMP'     => '1 SMA/SMK',
            '2 SMP'     => '3 SMP',
            '1 SMP'     => '2 SMP',
            '6 SD'      => '1 SMP',
            '5 SD'      => '6 SD',
            '4 SD'      => '5 SD',
            '3 SD'      => '4 SD',
            '2 SD'      => '3 SD',
            '1 SD'      => '2 SD',
            'TK'        => '1 SD',
            'PAUD'      => 'TK',
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($perubahan) {
            foreach ($perubahan as $dari => $ke) {
                Generus::where('jenjang', $dari)->update(['jenjang' => $ke]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Alhamdulillah, seluruh Generus berhasil dinaikkan 1 tingkat!']);
    }

    public function demoteAll()
    {
        // URUTAN PENTING: Dari terendah ke tertinggi agar tidak dobel turun.
        // PAUD tidak ada di daftar karena tidak bisa turun lagi.
        // USMAN tidak ada di daftar karena USMAN tidak boleh turun jadi SMA.
        $perubahan = [
            'TK'        => 'PAUD',
            '1 SD'      => 'TK',
            '2 SD'      => '1 SD',
            '3 SD'      => '2 SD',
            '4 SD'      => '3 SD',
            '5 SD'      => '4 SD',
            '6 SD'      => '5 SD',
            '1 SMP'     => '6 SD',
            '2 SMP'     => '1 SMP',
            '3 SMP'     => '2 SMP',
            '1 SMA/SMK' => '3 SMP',
            '2 SMA/SMK' => '1 SMA/SMK',
            '3 SMA/SMK' => '2 SMA/SMK',
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($perubahan) {
            foreach ($perubahan as $dari => $ke) {
                Generus::where('jenjang', $dari)->update(['jenjang' => $ke]);
            }
        });

        return response()->json(['success' => true, 'message' => 'Status jenjang dikembalikan. Seluruh Generus berhasil diturunkan 1 tingkat.']);
    }

    public function destroyAll()
    {
        // Gunakan Eloquent untuk menghapus data agar cascade berjalan jika didefinisikan,
        // atau hapus secara eksplisit data terkait terlebih dahulu.
        
        \Illuminate\Support\Facades\DB::transaction(function () {
            // Hapus semua absensi terlebih dahulu karena berhubungan erat dengan data Generus
            \App\Models\Attendance::query()->delete();
            
            // Hapus generus (gunakan delete() bukan truncate() agar tidak terkena error Foreign Key Constraint di MySQL)
            Generus::query()->delete();

            // Sesuai permintaan: Hapus semua user selain Admin
            \App\Models\User::where('role', '!=', 'admin')->delete();
        });
        
        return response()->json(['success' => true, 'message' => 'Semua data Generus dan Absensi terkait berhasil dihapus bersih!']);
    }

    public function exportCsv()
    {
        $fileName = 'data_generus_' . date('Ymd_His') . '.csv';
        $generus = Generus::all();

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['ID', 'Nama Lengkap', 'Kelompok', 'Status', 'Tempat Lahir', 'Tanggal Lahir', 'Umur', 'Jenis Kelamin', 'Jenjang', 'Keterangan', 'Libur', 'Nama Ayah', 'Nama Ibu', 'No HP', 'Akun Media', 'Hobi', 'Dibuat Pada'];

        $callback = function() use($generus, $columns) {
            $file = fopen('php://output', 'w');
            // Tambahkan BOM untuk kompatibilitas UTF-8 di Excel
            fputs($file, "\xEF\xBB\xBF");
            // Gunakan separator titik koma (;) agar Excel bahasa Indonesia bisa memisahkan kolom secara otomatis
            fputcsv($file, $columns, ';');

            foreach ($generus as $g) {
                fputcsv($file, [
                    $g->id,
                    $g->nama_lengkap,
                    $g->kelompok,
                    $g->status,
                    $g->tempat_lahir,
                    $g->tanggal_lahir,
                    $g->umur,
                    $g->jenis_kelamin,
                    $g->jenjang,
                    $g->keterangan,
                    $g->libur,
                    $g->nama_ayah,
                    $g->nama_ibu,
                    $g->no_hp,
                    $g->akun_media,
                    $g->hobi,
                    $g->created_at ? $g->created_at->format('Y-m-d H:i:s') : '',
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}