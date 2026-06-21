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

        // Hitung total kehadiran global
        $totalKehadiran = Attendance::where('generus_id', $generusId)->count();
        $totalHadir = Attendance::where('generus_id', $generusId)->where('status', 'hadir')->count();
        $totalAlfa = Attendance::where('generus_id', $generusId)->where('status', 'alpa')->count();
        $totalIzin = Attendance::where('generus_id', $generusId)->where('status', 'izin')->count();
        $totalSakit = Attendance::where('generus_id', $generusId)->where('status', 'sakit')->count();

        // Rincian per tipe kegiatan
        $eventTypes = EventType::all();
        $details = [];

        foreach ($eventTypes as $type) {
            $typeId = $type->id;
            
            $hadir = Attendance::where('generus_id', $generusId)
                ->whereHas('event', function($q) use ($typeId) {
                    $q->where('event_type_id', $typeId);
                })->where('status', 'hadir')->count();
                
            $alfa = Attendance::where('generus_id', $generusId)
                ->whereHas('event', function($q) use ($typeId) {
                    $q->where('event_type_id', $typeId);
                })->where('status', 'alpa')->count();

            $izin = Attendance::where('generus_id', $generusId)
                ->whereHas('event', function($q) use ($typeId) {
                    $q->where('event_type_id', $typeId);
                })->where('status', 'izin')->count();

            $sakit = Attendance::where('generus_id', $generusId)
                ->whereHas('event', function($q) use ($typeId) {
                    $q->where('event_type_id', $typeId);
                })->where('status', 'sakit')->count();

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

        $history = Attendance::with(['event.eventType'])
            ->where('generus_id', $generusId)
            ->get()
            ->sortByDesc(function ($att) {
                return $att->event->event_date;
            })
            ->values()
            ->map(function ($att) {
                return [
                    'id' => $att->id,
                    'date' => $att->event->event_date,
                    'event_name' => $att->event->name,
                    'event_type' => $att->event->eventType->name ?? 'Kegiatan',
                    'status' => $att->status,
                    'time_arrived' => $att->time_arrived,
                    'is_late' => $att->is_late,
                ];
            });

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
