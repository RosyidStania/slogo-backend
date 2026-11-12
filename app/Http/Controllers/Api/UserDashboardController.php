<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Attendance;
use App\Models\EventType;

class UserDashboardController extends Controller
{
    public function report(Request $request)
    {
        $user = $request->user();
        
        // Cek apakah user punya data generus
        if (!$user->generus) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum tertaut dengan data Generus.'
            ], 404);
        }

        $generusId = $user->generus->id;

        // Hitung total kehadiran global dalam 1 query agregat
        $globalCounts = Attendance::where('generus_id', $generusId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'alpa' THEN 1 ELSE 0 END) as alfa,
                SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as izin,
                SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as sakit
            ")
            ->first();

        $totalKehadiran = (int) ($globalCounts->total ?? 0);
        $totalHadir = (int) ($globalCounts->hadir ?? 0);
        $totalAlfa = (int) ($globalCounts->alfa ?? 0);
        $totalIzin = (int) ($globalCounts->izin ?? 0);
        $totalSakit = (int) ($globalCounts->sakit ?? 0);

        // Ambil seluruh riwayat absensi dengan JOIN agar tersortir di DB tanpa N+1 / whereHas loop
        $attendances = Attendance::query()
            ->join('events', 'attendances.event_id', '=', 'events.id')
            ->where('attendances.generus_id', $generusId)
            ->orderByDesc('events.event_date')
            ->select('attendances.*')
            ->with([
                'event:id,name,event_date,event_type_id',
                'event.eventType:id,name',
            ])
            ->get();

        // Hitung rincian per tipe kegiatan dari data absensi yang sudah dimuat
        $countsByType = [];
        foreach ($attendances as $att) {
            $typeId = $att->event?->event_type_id;
            if (!$typeId) {
                continue;
            }
            if (!isset($countsByType[$typeId])) {
                $countsByType[$typeId] = ['hadir' => 0, 'alpa' => 0, 'izin' => 0, 'sakit' => 0];
            }
            if (isset($countsByType[$typeId][$att->status])) {
                $countsByType[$typeId][$att->status]++;
            }
        }

        $eventTypes = EventType::select('id', 'name')->get();
        $details = [];
        foreach ($eventTypes as $type) {
            $typeCounts = $countsByType[$type->id] ?? null;
            if (!$typeCounts) {
                continue;
            }
            $hadir = $typeCounts['hadir'];
            $alfa = $typeCounts['alpa'];
            $izin = $typeCounts['izin'];
            $sakit = $typeCounts['sakit'];
            $total = $hadir + $alfa + $izin + $sakit;

            if ($total > 0) {
                $details[] = [
                    'event_type' => $type->name,
                    'total' => $total,
                    'hadir' => $hadir,
                    'alfa' => $alfa,
                    'izin' => $izin,
                    'sakit' => $sakit,
                    'percentage' => round(($hadir / $total) * 100, 1)
                ];
            }
        }

        $history = $attendances->map(function ($att) {
            return [
                'id' => $att->id,
                'date' => $att->event->event_date,
                'event_name' => $att->event->name,
                'event_type' => $att->event->eventType?->name ?? 'Kegiatan',
                'status' => $att->status,
                'time_arrived' => $att->time_arrived,
                'is_late' => $att->is_late,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'generus' => $user->generus,
                'summary' => [
                    'total' => $totalKehadiran,
                    'hadir' => $totalHadir,
                    'alfa' => $totalAlfa,
                    'izin' => $totalIzin,
                    'sakit' => $totalSakit,
                    'percentage' => $totalKehadiran > 0 ? round(($totalHadir / $totalKehadiran) * 100, 1) : 0
                ],
                'details' => $details,
                'history' => $history
            ]
        ], 200);
    }
}
