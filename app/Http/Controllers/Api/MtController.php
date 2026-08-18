<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Generus;
use App\Models\Event;
use App\Models\Attendance;
use App\Models\User;

class MtController extends Controller
{
    private function getMtKelompok($user)
    {
        $generus = $user->generus;
        if (!$generus) {
            return null;
        }
        return $generus->kelompok;
    }

    public function groupMembers(Request $request)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $generus = Generus::where('kelompok', $kelompok)
            ->orderByRaw("CASE WHEN status = 'Aktif' THEN 1 ELSE 2 END")
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
            ->orderBy('nama_lengkap')
            ->get();

        return response()->json(['success' => true, 'kelompok' => $kelompok, 'data' => $generus]);
    }

    public function updateMember(Request $request, $id)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $generus = Generus::find($id);
        if (!$generus) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        }

        if ($generus->kelompok !== $kelompok) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak. Generus ini bukan dari kelompok Anda.'], 403);
        }

        $validated = $request->validate([
            'nama_lengkap' => 'required|string|max:255',
            'jenis_kelamin' => 'required|in:L,P',
            'tempat_lahir' => 'nullable|string|max:255',
            'tanggal_lahir' => 'nullable|date',
            'umur' => 'nullable|integer',
            'nama_ayah' => 'nullable|string|max:255',
            'nama_ibu' => 'nullable|string|max:255',
            'no_hp' => 'nullable|string|max:20',
            'akun_media' => 'nullable|string|max:255',
            'hobi' => 'nullable|string',
            'kelompok' => 'nullable|string|max:255',
            'jenjang' => 'nullable|string|max:255',
            'is_pengurus' => 'nullable|boolean',
            'status' => 'required|in:aktif,tidak aktif,pasif',
            'keterangan' => 'nullable|string',
            'libur' => 'nullable|string'
        ]);

        $generus->update($validated);
        
        if ($generus->user_id) {
            $user = User::find($generus->user_id);
            if ($user && $user->role !== 'admin') {
                $newRole = strtoupper($validated['jenjang'] ?? '') === 'MT' ? 'mt' : 'user';
                $user->update([
                    'name' => $validated['nama_lengkap'],
                    'role' => $newRole,
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => $generus, 'message' => 'Data berhasil diupdate']);
    }

    public function groupAttendance(Request $request)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $typeId = $request->query('event_type_id');
        $year = $request->query('year', date('Y'));

        // Sama seperti admin, get available years
        $years = Event::selectRaw('YEAR(event_date) as year')
            ->whereNotNull('event_date')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year');
        if ($years->isEmpty()) {
            $years = [date('Y')];
        }
        
        $eventTypes = \App\Models\EventType::all();

        if (!$typeId) {
            return response()->json([
                'success' => true, 
                'availableYears' => $years, 
                'eventTypes' => $eventTypes,
                'kelompok' => $kelompok,
                'message' => 'Silakan pilih jenis acara'
            ]);
        }

        $events = Event::where('event_type_id', $typeId)
            ->whereYear('event_date', $year)
            ->orderBy('event_date', 'asc')
            ->get();

        $eventIds = $events->pluck('id')->toArray();

        $eventType = \App\Models\EventType::find($typeId);
        $targetKategori = $eventType ? ($eventType->target_kategori ?? []) : [];

        $generusQuery = Generus::where('kelompok', $kelompok)
            ->orderByRaw("CASE WHEN status = 'Aktif' THEN 1 ELSE 2 END")
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

        if (!empty($targetKategori)) {
            $generusQuery->where(function ($query) use ($targetKategori) {
                $query->whereIn('jenjang', $targetKategori);
                if (in_array('PENGURUS', $targetKategori)) {
                    $query->orWhere('is_pengurus', true);
                }
            });
        }

        $allGenerus = $generusQuery->get();

        $attendances = Attendance::whereIn('event_id', $eventIds)->get();
        $attByGenerus = $attendances->groupBy('generus_id');

        $result = [];
        foreach ($allGenerus as $g) {
            $gAtts = $attByGenerus->get($g->id) ?? collect();
            $eventStatus = [];

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
                'is_pengurus' => $g->is_pengurus,
                'kelompok' => $g->kelompok,
                'status' => $g->status,
                'umur' => $g->umur,
                'jenis_kelamin' => $g->jenis_kelamin,
                'events_attendance' => $eventStatus
            ];
        }

        return response()->json([
            'success' => true, 
            'year' => $year,
            'event_type_id' => $typeId,
            'events' => $events,
            'data' => $result,
            'availableYears' => $years,
            'eventTypes' => $eventTypes,
            'kelompok' => $kelompok
        ]);
    }

    public function groupStatistics(Request $request)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $year = $request->query('year', date('Y'));
        
        $generus = Generus::where('kelompok', $kelompok)->get();
        $generusIds = $generus->pluck('id')->toArray();
        
        $totalAnggota = $generus->count();
        $anggotaAktif = $generus->where('status', 'aktif')->count();
        
        $eventsThisYear = Event::whereYear('event_date', $year)->get();
        $totalAcara = 0;
        
        $attendances = Attendance::whereIn('generus_id', $generusIds)
            ->whereHas('event', function($q) use ($year) {
                $q->whereYear('event_date', $year);
            })->get();
            
        $totalKehadiran = $attendances->where('status', 'hadir')->count();
        $totalAbsen = $attendances->where('status', 'alpa')->count() + $attendances->where('status', 'izin')->count() + $attendances->where('status', 'sakit')->count();
        $totalAtt = $totalKehadiran + $totalAbsen;
        $rataKehadiran = $totalAtt > 0 ? round(($totalKehadiran / $totalAtt) * 100) : 0;
        
        $jenjangDistribution = [];
        foreach ($generus as $g) {
            $j = $g->jenjang ?: 'Tanpa Jenjang';
            $found = false;
            foreach ($jenjangDistribution as &$item) {
                if ($item['name'] === $j) {
                    $item['value']++;
                    $found = true;
                    break;
                }
            }
            unset($item);
            if (!$found) {
                $jenjangDistribution[] = ['name' => $j, 'value' => 1];
            }
        }
        
        $memberStats = [];
        foreach ($generus as $g) {
            $gAtts = $attendances->where('generus_id', $g->id);
            $h = $gAtts->where('status', 'hadir')->count();
            $tot = $gAtts->count();
            $memberStats[] = [
                'nama_lengkap' => $g->nama_lengkap,
                'jenjang' => $g->jenjang,
                'total_hadir' => $h,
                'total_absen' => $tot - $h,
                'percentage' => $tot > 0 ? round(($h / $tot) * 100) : 0
            ];
        }
        
        $perTypeStats = [];
        $eventTypes = \App\Models\EventType::all();
        foreach ($eventTypes as $type) {
            $typeEvents = $eventsThisYear->where('event_type_id', $type->id)->pluck('id')->toArray();
            if (empty($typeEvents)) continue;
            
            $typeAtts = $attendances->whereIn('event_id', $typeEvents);
            $totalEventAtts = $typeAtts->count();
            if ($totalEventAtts > 0) {
                $totalAcara++;
                $h = $typeAtts->where('status', 'hadir')->count();
                $a = $typeAtts->where('status', 'alpa')->count();
                $i = $typeAtts->whereIn('status', ['izin', 'sakit'])->count();
                
                $perTypeStats[] = [
                    'name' => $type->name,
                    'total_hadir' => $h,
                    'total_alfa' => $a,
                    'total_izin' => $i,
                    'percentage' => round(($h / $totalEventAtts) * 100)
                ];
            }
        }
        
        usort($memberStats, function($a, $b) {
            return $b['percentage'] <=> $a['percentage'];
        });

        return response()->json([
            'success' => true,
            'kelompok' => $kelompok,
            'stats' => [
                'totalAnggota' => $totalAnggota,
                'anggotaAktif' => $anggotaAktif,
                'rataKehadiran' => $rataKehadiran,
                'totalAcara' => $totalAcara
            ],
            'memberStats' => $memberStats,
            'jenjangDistribution' => $jenjangDistribution,
            'perTypeStats' => $perTypeStats
        ]);
    }
}
