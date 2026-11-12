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
        return $user->kelompok ?: $user->generus?->kelompok;
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

        if (strcasecmp($generus->kelompok ?? '', $kelompok ?? '') !== 0) {
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
            'is_pengurus_muda_mudi' => 'nullable|boolean',
            'is_ketua_wakil_kelompok' => 'nullable|boolean',
            'status' => 'required|in:aktif,tidak aktif,pasif',
            'keterangan' => 'nullable|string',
            'libur' => 'nullable|string'
        ]);

        $validated['kelompok'] = $kelompok; // Lock kelompok, prevent reassignment
        $validated['is_pengurus'] = $request->boolean('is_pengurus');
        $validated['is_pengurus_muda_mudi'] = $request->boolean('is_pengurus_muda_mudi');
        $validated['is_ketua_wakil_kelompok'] = $request->boolean('is_ketua_wakil_kelompok');
        $generus->update($validated);
        
        if ($generus->user_id) {
            $user = User::find($generus->user_id);
            if ($user && !in_array($user->role, ['admin', 'operator_absensi', 'absen_kelompok'])) {
                $newRole = strtoupper($validated['jenjang'] ?? '') === 'MT' ? 'mt' : 'user';
                $user->update([
                    'name' => $validated['nama_lengkap'],
                    'role' => $newRole,
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => $generus, 'message' => 'Data berhasil diupdate']);
    }

    public function storeMember(Request $request)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'nama_lengkap'  => 'required|string|max:255',
            'jenis_kelamin' => 'required|in:L,P',
            'jenjang'       => 'nullable|string|max:255',
            'is_pengurus'   => 'nullable|boolean',
            'status'        => 'required|in:aktif,tidak aktif,pasif',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()], 422);
        }

        $trimmedName = trim($request->nama_lengkap);

        $exists = Generus::whereRaw('LOWER(TRIM(nama_lengkap)) = ?', [mb_strtolower($trimmedName, 'UTF-8')])
            ->whereRaw('LOWER(TRIM(kelompok)) = ?', [mb_strtolower(trim($kelompok), 'UTF-8')])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => "Peserta dengan nama '{$trimmedName}' di kelompok '{$kelompok}' sudah terdaftar."
            ], 422);
        }

        $baseUsername = strtolower(str_replace(' ', '', $request->nama_lengkap));
        $username = $baseUsername;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }

        $role = strtoupper($request->jenjang ?? '') === 'MT' ? 'mt' : 'user';

        $user = User::create([
            'name'     => $request->nama_lengkap,
            'username' => $username,
            'password' => $baseUsername . '123',
            'role'     => $role,
            'kelompok' => $kelompok,
        ]);
        $userId = $user->id;

        $dataGenerus = $request->all();
        $dataGenerus['kelompok'] = $kelompok;
        $dataGenerus['user_id'] = $userId;
        $dataGenerus['is_pengurus'] = $request->boolean('is_pengurus');
        $dataGenerus['is_pengurus_muda_mudi'] = $request->boolean('is_pengurus_muda_mudi');
        $dataGenerus['is_ketua_wakil_kelompok'] = $request->boolean('is_ketua_wakil_kelompok');

        $generus = Generus::create($dataGenerus);

        return response()->json(['success' => true, 'message' => 'Data peserta berhasil ditambahkan', 'data' => $generus], 201);
    }

    public function destroyMember(Request $request, $id)
    {
        $kelompok = $this->getMtKelompok($request->user());
        if (!$kelompok) {
            return response()->json(['success' => false, 'message' => 'Anda tidak memiliki kelompok yang valid.'], 403);
        }

        $generus = Generus::find($id);
        if (!$generus) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        }

        if (strcasecmp($generus->kelompok ?? '', $kelompok ?? '') !== 0) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak. Generus ini bukan dari kelompok Anda.'], 403);
        }

        if ($generus->user_id) {
            $user = User::find($generus->user_id);
            if ($user && !in_array($user->role, ['admin'])) {
                $user->delete();
            }
        }

        $generus->delete();

        return response()->json(['success' => true, 'message' => 'Data peserta berhasil dihapus']);
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

        $typeIds = is_array($typeId) ? $typeId : explode(',', $typeId);

        $events = Event::select(['id', 'event_type_id', 'name', 'event_date', 'start_time', 'kelompok', 'is_closed'])
            ->whereIn('event_type_id', $typeIds)
            ->whereBetween('event_date', ["{$year}-01-01", "{$year}-12-31"])
            ->where(function($q) use ($kelompok) {
                $q->whereNull('kelompok')->orWhere('kelompok', $kelompok);
            })
            ->orderBy('event_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        $eventIds = $events->pluck('id')->toArray();

        $eventTypeModels = \App\Models\EventType::whereIn('id', $typeIds)->get();
        $targetKategori = [];
        foreach ($eventTypeModels as $et) {
            if (!empty($et->target_kategori)) {
                $targetKategori = array_merge($targetKategori, $et->target_kategori);
            }
        }
        $targetKategori = array_unique($targetKategori);

        $generusQuery = Generus::select([
                'id', 'nama_lengkap', 'jenjang', 'kelompok', 'status',
                'umur', 'jenis_kelamin', 'is_pengurus',
                'is_pengurus_muda_mudi', 'is_ketua_wakil_kelompok'
            ])
            ->where('kelompok', $kelompok)
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
                'is_pengurus' => $g->is_pengurus,
                'is_pengurus_muda_mudi' => $g->is_pengurus_muda_mudi,
                'is_ketua_wakil_kelompok' => $g->is_ketua_wakil_kelompok,
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
        
        $generus = Generus::select([
                'id', 'nama_lengkap', 'jenjang', 'status',
                'is_pengurus', 'is_pengurus_muda_mudi', 'is_ketua_wakil_kelompok'
            ])
            ->where('kelompok', $kelompok)
            ->get();
        $generusIds = $generus->pluck('id')->all();
        
        $totalAnggota = $generus->count();
        $anggotaAktif = $generus->where('status', 'aktif')->count();
        
        $eventsThisYear = Event::select(['id', 'event_type_id'])
            ->whereBetween('event_date', ["{$year}-01-01", "{$year}-12-31"])
            ->where(function($q) use ($kelompok) {
                $q->whereNull('kelompok')->orWhere('kelompok', $kelompok);
            })->get();
        $totalAcara = $eventsThisYear->count();
        $eventIdsThisYear = $eventsThisYear->pluck('id')->all();
        
        $attendances = (!empty($generusIds) && !empty($eventIdsThisYear))
            ? Attendance::select(['id', 'event_id', 'generus_id', 'status'])
                ->whereIn('generus_id', $generusIds)
                ->whereIn('event_id', $eventIdsThisYear)
                ->get()
            : collect();
            
        $totalKehadiran = 0;
        $totalAbsen = 0;
        $attByGenerus = [];
        $attByEvent = [];
        foreach ($attendances as $att) {
            if ($att->status === 'hadir') {
                $totalKehadiran++;
            } elseif (in_array($att->status, ['alpa', 'izin', 'sakit'], true)) {
                $totalAbsen++;
            }
            $attByGenerus[$att->generus_id][] = $att;
            $attByEvent[$att->event_id][] = $att;
        }
        $totalAtt = $totalKehadiran + $totalAbsen;
        $rataKehadiran = $totalAtt > 0 ? round(($totalKehadiran / $totalAtt) * 100) : 0;
        
        $jenjangCounts = [];
        foreach ($generus as $g) {
            $j = $g->jenjang ?: 'Tanpa Jenjang';
            $jenjangCounts[$j] = ($jenjangCounts[$j] ?? 0) + 1;
        }
        $jenjangDistribution = [];
        foreach ($jenjangCounts as $name => $value) {
            $jenjangDistribution[] = ['name' => $name, 'value' => $value];
        }
        
        $memberStats = [];
        foreach ($generus as $g) {
            $gAtts = $attByGenerus[$g->id] ?? [];
            $tot = count($gAtts);
            $h = 0;
            foreach ($gAtts as $att) {
                if ($att->status === 'hadir') {
                    $h++;
                }
            }
            $memberStats[] = [
                'nama_lengkap' => $g->nama_lengkap,
                'jenjang' => $g->jenjang,
                'is_pengurus' => $g->is_pengurus,
                'is_pengurus_muda_mudi' => $g->is_pengurus_muda_mudi,
                'is_ketua_wakil_kelompok' => $g->is_ketua_wakil_kelompok,
                'total_hadir' => $h,
                'total_absen' => $tot - $h,
                'percentage' => $tot > 0 ? round(($h / $tot) * 100) : 0
            ];
        }
        
        $perTypeStats = [];
        $eventIdsByType = [];
        foreach ($eventsThisYear as $ev) {
            $eventIdsByType[$ev->event_type_id][] = $ev->id;
        }
        $eventTypes = \App\Models\EventType::select(['id', 'name'])->get();
        foreach ($eventTypes as $type) {
            $typeEvents = $eventIdsByType[$type->id] ?? [];
            if (empty($typeEvents)) continue;

            $h = 0;
            $a = 0;
            $i = 0;
            $totalEventAtts = 0;
            foreach ($typeEvents as $evId) {
                foreach ($attByEvent[$evId] ?? [] as $att) {
                    $totalEventAtts++;
                    if ($att->status === 'hadir') {
                        $h++;
                    } elseif ($att->status === 'alpa') {
                        $a++;
                    } elseif ($att->status === 'izin' || $att->status === 'sakit') {
                        $i++;
                    }
                }
            }
            if ($totalEventAtts > 0) {
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
