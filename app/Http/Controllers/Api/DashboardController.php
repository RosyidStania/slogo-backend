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
    public function index(Request $request)
    {
        try {
            // 1. Hitung Metrik Utama
            $now = Carbon::now();
            $currentYear = $now->year;
            $yearStart = "{$currentYear}-01-01";
            $yearEnd = "{$currentYear}-12-31";

            $generusStats = Generus::selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'aktif' THEN 1 ELSE 0 END) as aktif
            ")->first();
            $totalGenerus = (int) ($generusStats->total ?? 0);
            $aktifGenerus = (int) ($generusStats->aktif ?? 0);

            $acaraBulanIni = Event::whereBetween('event_date', [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ])->count();

            $eventTypeIds = $request->input('event_type_ids', []);

            // ====================================================================
            // LOGIKA BARU: HITUNG PERSENTASE KEHADIRAN SESUAI STATUS (AKTIF/PASIF)
            // ====================================================================
            $attendanceAggQuery = DB::table('attendances')
                ->join('events', 'attendances.event_id', '=', 'events.id')
                ->join('generus', 'attendances.generus_id', '=', 'generus.id')
                ->whereBetween('events.event_date', [$yearStart, $yearEnd])
                ->whereIn('generus.status', ['aktif', 'pasif']);

            if (!empty($eventTypeIds)) {
                $attendanceAggQuery->whereIn('events.event_type_id', $eventTypeIds);
            }

            $attendanceAgg = $attendanceAggQuery->selectRaw("
                SUM(CASE WHEN generus.status = 'aktif' OR (generus.status = 'pasif' AND attendances.status = 'hadir') THEN 1 ELSE 0 END) as total_valid,
                SUM(CASE WHEN attendances.status = 'hadir' THEN 1 ELSE 0 END) as total_hadir
            ")->first();

            $totalValidAbsensi = (int) ($attendanceAgg->total_valid ?? 0);
            $totalHadir = (int) ($attendanceAgg->total_hadir ?? 0);

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
            $upcomingEvents = Event::where('event_date', '>=', Carbon::today()->toDateString())
                                   ->orderBy('event_date', 'asc')
                                   ->take(3)
                                   ->get();

            $excludeMt = $request->input('exclude_mt', 0);

            // 5. Peringkat Kehadiran Individu (Top 5 Tahun Ini)
            $topAttendeesQuery = Attendance::where('attendances.status', 'hadir')
                ->join('events', 'attendances.event_id', '=', 'events.id')
                ->leftJoin('generus', 'attendances.generus_id', '=', 'generus.id')
                ->whereBetween('events.event_date', [$yearStart, $yearEnd]);

            if (!empty($eventTypeIds)) {
                $topAttendeesQuery->whereIn('events.event_type_id', $eventTypeIds);
            }

            if ($excludeMt) {
                $topAttendeesQuery->where('generus.jenjang', '!=', 'MT');
            }

            $topAttendees = $topAttendeesQuery
                ->select(
                    'attendances.generus_id',
                    'generus.nama_lengkap',
                    'generus.kelompok',
                    'generus.jenjang',
                    'generus.is_pengurus',
                    DB::raw('count(attendances.id) as total_hadir')
                )
                ->groupBy(
                    'attendances.generus_id',
                    'generus.nama_lengkap',
                    'generus.kelompok',
                    'generus.jenjang',
                    'generus.is_pengurus'
                )
                ->orderBy('total_hadir', 'desc')
                ->take(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'nama_lengkap' => $item->nama_lengkap ?? 'Terhapus',
                        'kelompok' => $item->kelompok ?? '-',
                        'jenjang' => $item->jenjang ?? '-',
                        'is_pengurus' => $item->is_pengurus ?? 0,
                        'total_hadir' => $item->total_hadir
                    ];
                });

            // 6. Peringkat Kelompok Paling Aktif (Top 5 Tahun Ini)
            $topGroupsQuery = Attendance::where('attendances.status', 'hadir')
                ->join('events', 'attendances.event_id', '=', 'events.id')
                ->whereBetween('events.event_date', [$yearStart, $yearEnd])
                ->join('generus', 'attendances.generus_id', '=', 'generus.id');

            if (!empty($eventTypeIds)) {
                $topGroupsQuery->whereIn('events.event_type_id', $eventTypeIds);
            }

            if ($excludeMt) {
                $topGroupsQuery->where('generus.jenjang', '!=', 'MT');
            }

            $topGroups = $topGroupsQuery
                ->select('generus.kelompok', DB::raw('count(attendances.id) as total_hadir'))
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