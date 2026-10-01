<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Generus;
use App\Models\Attendance;

class ReportController extends Controller
{
    public function availableYears()
    {
        $years = Event::selectRaw('YEAR(event_date) as year')
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
        $events = Event::whereIn('event_type_id', $typeIds)
            ->whereYear('event_date', $year)
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

        // Ambil semua generus
        // Urutan: 
        // 1. Status (Aktif dulu, Nonaktif di bawah)
        // 2. Kelompok
        // 3. Jenjang (USMAN ke bawah sampai PAUD)
        $generusQuery = Generus::orderByRaw("CASE WHEN status = 'Aktif' THEN 1 ELSE 2 END")
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

        // Ambil semua absensi untuk event-event yang ditemukan
        $attendances = Attendance::whereIn('event_id', $eventIds)->get();

        // Kelompokkan absensi per generus_id
        $attByGenerus = $attendances->groupBy('generus_id');

        $result = [];
        foreach ($allGenerus as $g) {
            // Absensi milik generus ini
            $gAtts = $attByGenerus->get($g->id) ?? collect();
            $eventStatus = [];

            // Map status absensi berdasarkan event_id
            foreach ($events as $event) {
                $record = $gAtts->firstWhere('event_id', $event->id);
                if ($record) {
                    $status = $record->status; 
                    $initial = strtoupper(substr($status, 0, 1));
                    $eventStatus[$event->id] = $initial;
                } else {
                    $eventStatus[$event->id] = '-'; 
                }
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
}
