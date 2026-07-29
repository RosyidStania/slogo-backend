<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Generus;
use App\Models\Event;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        try {
            // 1. Hitung Metrik Utama
            $totalGenerus = Generus::count();
            $aktifGenerus = Generus::where('status', 'aktif')->count();
            $acaraBulanIni = Event::whereMonth('event_date', Carbon::now()->month)
                                  ->whereYear('event_date', Carbon::now()->year)
                                  ->count();

            // ====================================================================
            // LOGIKA BARU: HITUNG PERSENTASE KEHADIRAN SESUAI STATUS (AKTIF/PASIF)
            // ====================================================================
            
            // A. Hitung Total Pembagi (Denominator)
            // Diambil dari: Seluruh data absensi anggota 'aktif' + Data anggota 'pasif' TAPI KHUSUS yang 'hadir' saja
            $totalValidAbsensi = Attendance::where(function($query) {
                // Kondisi 1: Milik Generus Aktif (apapun status absensinya: hadir/izin/alpa)
                $query->whereHas('generus', function($q) {
                    $q->where('status', 'aktif');
                })
                // Kondisi 2: Milik Generus Pasif (HANYA JIKA status absensinya 'hadir')
                ->orWhere(function($q2) {
                    $q2->where('status', 'hadir')
                       ->whereHas('generus', function($q3) {
                           $q3->where('status', 'pasif');
                       });
                });
            })->count();

            // B. Hitung Total Hadir (Numerator)
            // Diambil dari: Semua status 'hadir' milik anggota 'aktif' maupun 'pasif'
            $totalHadir = Attendance::where('status', 'hadir')
                ->whereHas('generus', function($q) {
                    $q->whereIn('status', ['aktif', 'pasif']);
                })->count();

            // C. Kalkulasi Persentase
            $rataKehadiran = $totalValidAbsensi > 0 ? round(($totalHadir / $totalValidAbsensi) * 100) : 0;
            
            // ====================================================================

            // 2. Data Grafik Bar (Demografi Kelompok)
            $demografi = Generus::select('kelompok as name', DB::raw('count(*) as jumlah'))
                                ->where('status', 'aktif')
                                ->groupBy('kelompok')
                                ->orderBy('jumlah', 'desc')
                                ->get();

            // 3. Data Grafik Pie (Sebaran Kategori)
            $kategori = Generus::select('jenjang as name', DB::raw('count(*) as value'))
                               ->groupBy('jenjang')
                               ->orderBy('value', 'desc')
                               ->get();

            // 4. Data Acara Terdekat (Hari ini ke depan, max 3 acara)
            $upcomingEvents = Event::where('event_date', '>=', Carbon::today())
                                   ->orderBy('event_date', 'asc')
                                   ->take(3)
                                   ->get();

            // 5. Peringkat Kehadiran Individu (Top 5 Tahun Ini)
            $topAttendees = Attendance::where('status', 'hadir')
                ->whereYear('created_at', Carbon::now()->year)
                ->select('generus_id', DB::raw('count(*) as total_hadir'))
                ->groupBy('generus_id')
                ->orderBy('total_hadir', 'desc')
                ->take(5)
                ->with('generus:id,nama_lengkap,kelompok,jenjang')
                ->get()
                ->map(function ($item) {
                    return [
                        'nama_lengkap' => $item->generus ? $item->generus->nama_lengkap : 'Terhapus',
                        'kelompok' => $item->generus ? $item->generus->kelompok : '-',
                        'jenjang' => $item->generus ? $item->generus->jenjang : '-',
                        'total_hadir' => $item->total_hadir
                    ];
                });

            // 6. Peringkat Kelompok Paling Aktif (Top 5 Tahun Ini)
            $topGroups = Attendance::where('attendances.status', 'hadir')
                ->whereYear('attendances.created_at', Carbon::now()->year)
                ->join('generus', 'attendances.generus_id', '=', 'generus.id')
                ->select('generus.kelompok', DB::raw('count(*) as total_hadir'))
                ->groupBy('generus.kelompok')
                ->orderBy('total_hadir', 'desc')
                ->take(5)
                ->get();

            return response()->json([
                'success' => true,
                'stats' => [
                    'totalGenerus' => $totalGenerus,
                    'aktifGenerus' => $aktifGenerus,
                    'rataKehadiran' => $rataKehadiran,
                    'acaraBulanIni' => $acaraBulanIni
                ],
                'demografi' => $demografi,
                'kategori' => $kategori,
                'upcomingEvents' => $upcomingEvents,
                'topAttendees' => $topAttendees,
                'topGroups' => $topGroups
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data dashboard: ' . $e->getMessage()
            ], 500);
        }
    }
}