<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Generus;
use App\Models\Attendance;
use App\Services\CategoryStatisticsService;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function availableYears()
    {
        $yearExpr = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', event_date) AS INTEGER) as year"
            : 'YEAR(event_date) as year';

        $years = Event::selectRaw($yearExpr)
            ->whereNotNull('event_date')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');

        if ($years->isEmpty()) {
            $years = [date('Y')];
        }

        return response()->json(['success' => true, 'years' => $years]);
    }

    public function attendanceByType(Request $request)
    {
        $typeId = $request->query('event_type_id');
        $year = $request->query('year', date('Y'));

        if (!$typeId) {
            return response()->json(['success' => false, 'message' => 'Parameter event_type_id wajib diisi'], 400);
        }

        $typeIds = is_array($typeId) ? $typeId : explode(',', $typeId);

        // Ambil semua event pada tahun dan tipe tersebut
        // Urutkan asc agar jika ada >1 event di bulan yang sama, event yang terakhir (override) yang dipakai
        $events = Event::select(['id', 'event_type_id', 'name', 'event_date', 'start_time', 'kelompok', 'is_closed'])
            ->whereIn('event_type_id', $typeIds)
            ->whereBetween('event_date', ["{$year}-01-01", "{$year}-12-31"])
            ->orderBy('event_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        // Map event IDs
        $eventIds = $events->pluck('id')->toArray();

        // Ambil kategori acara untuk mengetahui siapa saja pesertanya
        $eventTypes = \App\Models\EventType::whereIn('id', $typeIds)->get();
        $targetKategori = [];
        foreach ($eventTypes as $et) {
            if (!empty($et->target_kategori)) {
                $targetKategori = array_merge($targetKategori, $et->target_kategori);
            }
        }
        $targetKategori = array_unique($targetKategori);

        // Ambil semua generus (kolom yang diperlukan saja)
        // Urutan: 
        // 1. Status (Aktif dulu, Nonaktif di bawah)
        // 2. Kelompok
        // 3. Jenjang (USMAN ke bawah sampai PAUD)
        $generusQuery = Generus::select([
                'id', 'nama_lengkap', 'jenjang', 'kelompok', 'status',
                'umur', 'jenis_kelamin', 'is_pengurus',
                'is_pengurus_muda_mudi', 'is_ketua_wakil_kelompok'
            ])
            ->orderByRaw("CASE WHEN status = 'Aktif' THEN 1 ELSE 2 END")
            ->orderBy('kelompok')
            ->orderByRaw("CASE 
                WHEN jenjang = 'MT' THEN 0
                WHEN jenjang = 'USMAN' THEN 1 
                WHEN jenjang = '3 SMA/SMK' THEN 2 
                WHEN jenjang = '2 SMA/SMK' THEN 3 
                WHEN jenjang = '1 SMA/SMK' THEN 4 
                WHEN jenjang = '3 SMP' THEN 5 
                WHEN jenjang = '2 SMP' THEN 6 
                WHEN jenjang = '1 SMP' THEN 7 
                WHEN jenjang = '6 SD' THEN 8 
                WHEN jenjang = '5 SD' THEN 9 
                WHEN jenjang = '4 SD' THEN 10 
                WHEN jenjang = '3 SD' THEN 11 
                WHEN jenjang = '2 SD' THEN 12 
                WHEN jenjang = '1 SD' THEN 13 
                WHEN jenjang = 'TK' THEN 14 
                WHEN jenjang = 'PAUD' THEN 15 
                ELSE 99 END")
            ->orderBy('nama_lengkap');

        // Jika event type memiliki target peserta spesifik, filter generus yang tampil
        if (!empty($targetKategori)) {
            $generusQuery->where(function ($query) use ($targetKategori) {
                $query->whereIn('jenjang', $targetKategori);
                
                if (in_array('PENGURUS', $targetKategori) || in_array('PENGURUS USMAN', $targetKategori)) {
                    $query->orWhere('is_pengurus', true);
                }
                if (in_array('PENGURUS MUDA MUDI', $targetKategori)) {
                    $query->orWhere('is_pengurus_muda_mudi', true);
                }
                if (in_array('KETUA/WAKIL KELOMPOK', $targetKategori)) {
                    $query->orWhere('is_ketua_wakil_kelompok', true);
                }
            });
        }

        $allGenerus = $generusQuery->get();

        // Ambil semua absensi untuk event-event yang ditemukan (lookup O(1) per sel)
        $attLookup = [];
        if (!empty($eventIds)) {
            $attendances = Attendance::select(['event_id', 'generus_id', 'status'])
                ->whereIn('event_id', $eventIds)
                ->get();

            foreach ($attendances as $att) {
                if (!isset($attLookup[$att->generus_id][$att->event_id])) {
                    $attLookup[$att->generus_id][$att->event_id] = strtoupper(substr($att->status, 0, 1));
                }
            }
        }

        $result = [];
        foreach ($allGenerus as $g) {
            $gAttMap = $attLookup[$g->id] ?? [];
            $eventStatus = [];

            foreach ($events as $event) {
                $eventStatus[$event->id] = $gAttMap[$event->id] ?? '-';
            }

            $result[] = [
                'id' => $g->id,
                'nama_lengkap' => $g->nama_lengkap,
                'jenjang' => $g->jenjang,
                'kelompok' => $g->kelompok,
                'status' => $g->status,
                'umur' => $g->umur,
                'jenis_kelamin' => $g->jenis_kelamin,
                'is_pengurus' => $g->is_pengurus,
                'is_pengurus_muda_mudi' => $g->is_pengurus_muda_mudi,
                'is_ketua_wakil_kelompok' => $g->is_ketua_wakil_kelompok,
                'events_attendance' => $eventStatus
            ];
        }

        return response()->json([
            'success' => true, 
            'year' => $year,
            'event_type_id' => $typeId,
            'events' => $events,
            'data' => $result
        ]);
    }

    public function categoryStatistics(Request $request, CategoryStatisticsService $service): JsonResponse
    {
        $validated = $request->validate([
            'start_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'end_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'scope' => ['nullable', 'in:all,desa,kelompok'],
            'kelompok' => ['nullable', 'string', 'max:100'],
        ]);

        $nowWib = Carbon::now(CategoryStatisticsService::TIMEZONE);
        $scope = $validated['scope'] ?? 'all';
        $kelompok = $validated['kelompok'] ?? null;
        $startMonth = $validated['start_month'] ?? null;
        $endMonth = $validated['end_month'] ?? null;

        if ($startMonth === null || $endMonth === null) {
            $resolvedPeriod = $service->resolvePeriod(
                $startMonth,
                $endMonth,
                $kelompok,
                $nowWib->toDateString(),
                $scope
            );
            $startMonth = $resolvedPeriod['start_month'];
            $endMonth = $resolvedPeriod['end_month'];
        }

        if ($startMonth > $endMonth) {
            return response()->json([
                'success' => false,
                'message' => 'Bulan mulai tidak boleh lebih besar dari bulan akhir.',
            ], 422);
        }

        $data = $service->buildStatistics($startMonth, $endMonth, $scope, $kelompok, $nowWib);

        return response()->json(array_merge(['success' => true], $data), 200);
    }
}
