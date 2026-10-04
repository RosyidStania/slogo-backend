<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Event::withCount('attendances')->orderBy('event_date', 'desc')->orderBy('start_time', 'desc');
        
        // Jika user adalah MT, hanya tampilkan acara milik kelompoknya yang event_type-nya dicentang "Absensi Perkelompok"
        if ($user && $user->role === 'absen_kelompok') {
            if (empty($user->kelompok)) {
                return response()->json(['success' => true, 'data' => []], 200);
            }
            $query->where('kelompok', $user->kelompok)
                  ->whereHas('eventType', function ($q) {
                      $q->where('is_group_attendance', true);
                  });
        }

        $events = $query->get();
        
        $allGenerus = \App\Models\Generus::whereIn('status', ['aktif', 'pasif'])->get();

        foreach($events as $event) {
            $targetKategori = json_decode($event->target_kategori, true) ?: [];
            
            // Filter generus berdasarkan kelompok acara jika ada
            $filteredGenerus = $allGenerus;
            if (!empty($event->kelompok)) {
                $filteredGenerus = $allGenerus->filter(fn($g) => strcasecmp($g->kelompok, $event->kelompok) === 0);
            }
            
            $targetCount = 0;
            if (empty($targetKategori)) {
                $targetCount = $filteredGenerus->count();
            } else {
                foreach($filteredGenerus as $g) {
                    $j = strtolower($g->jenjang ?? '');
                    $match = false;
                    foreach($targetKategori as $t) {
                        $tLower = strtolower($t);
                        if ($tLower === 'pengurus' || $tLower === 'pengurus usman') {
                            if ($g->is_pengurus) $match = true;
                        } elseif ($tLower === 'pengurus muda mudi') {
                            if ($g->is_pengurus_muda_mudi) $match = true;
                        } elseif ($tLower === 'ketua/wakil kelompok') {
                            if ($g->is_ketua_wakil_kelompok) $match = true;
                        } else {
                            if (str_contains($j, $tLower)) $match = true;
                        }
                    }
                    if ($match) $targetCount++;
                }
            }
            $event->target_count = $targetCount;
            $event->is_closed = (bool) $event->is_closed;
            $event->is_completed = $event->is_closed || (!$event->allow_other_participants && $targetCount > 0 && $event->attendances_count >= $targetCount);
        }

        return response()->json(['success' => true, 'data' => $events], 200);
    }

    public function show(Request $request, $id)
    {
        $event = Event::withCount('attendances')->find($id);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $targetKategori = json_decode($event->target_kategori, true) ?: [];
        $allGenerus = \App\Models\Generus::whereIn('status', ['aktif', 'pasif'])->get();

        $filteredGenerus = $allGenerus;
        if (!empty($event->kelompok)) {
            $filteredGenerus = $allGenerus->where('kelompok', $event->kelompok);
        }

        $targetCount = 0;
        if (empty($targetKategori)) {
            $targetCount = $filteredGenerus->count();
        } else {
            foreach ($filteredGenerus as $g) {
                $j = strtolower($g->jenjang ?? '');
                $match = false;
                foreach ($targetKategori as $t) {
                    $tLower = strtolower($t);
                    if ($tLower === 'pengurus' || $tLower === 'pengurus usman') {
                        if ($g->is_pengurus) $match = true;
                    } elseif ($tLower === 'pengurus muda mudi') {
                        if ($g->is_pengurus_muda_mudi) $match = true;
                    } elseif ($tLower === 'ketua/wakil kelompok') {
                        if ($g->is_ketua_wakil_kelompok) $match = true;
                    } else {
                        if (str_contains($j, $tLower)) $match = true;
                    }
                }
                if ($match) $targetCount++;
            }
        }
        $event->target_count = $targetCount;
        $event->is_closed = (bool) $event->is_closed;
        $event->is_completed = $event->is_closed || (!$event->allow_other_participants && $targetCount > 0 && $event->attendances_count >= $targetCount);

        return response()->json(['success' => true, 'data' => $event], 200);
    }

    public function toggleStatus(Request $request, $id)
    {
        $event = Event::findOrFail($id);

        $user = $request->user();
        if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }
        $event->is_closed = !$event->is_closed;
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Status acara berhasil diubah',
            'is_closed' => $event->is_closed
        ], 200);
    }

    public function store(Request $request)
        {
            $request->validate([
                'name' => 'required|string|max:255',
                'event_date' => 'required|date',
                'start_time' => 'required',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id',
                'allow_other_participants' => 'boolean',
                'infaq' => 'nullable|numeric'
            ]);

            $user = $request->user();
            $kelompok = ($user && $user->role === 'absen_kelompok') ? $user->kelompok : null;

            $event = Event::create([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id,
                'allow_other_participants' => $request->allow_other_participants ?? false,
                'kelompok' => $kelompok,
                'infaq' => $request->infaq
            ]);

            return response()->json(['success' => true, 'data' => $event], 201);
        }

        public function update(Request $request, $id)
        {
            $event = Event::findOrFail($id);

            $user = $request->user();
            if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'event_date' => 'required|date',
                'start_time' => 'required',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id',
                'allow_other_participants' => 'boolean',
                'infaq' => 'nullable|numeric'
            ]);

            $event->update([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id,
                'allow_other_participants' => $request->allow_other_participants ?? false,
                'infaq' => $request->infaq
            ]);

            return response()->json(['success' => true, 'data' => $event], 200);
        }

    public function destroy(Request $request, $id)
    {
        $event = Event::find($id);
        if ($event) {
            $user = $request->user();
            if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
            }
            // Hapus semua data absensi terkait agar tidak jadi sampah di database
            \App\Models\Attendance::where('event_id', $id)->delete();
            $event->delete();
        }
        return response()->json(['success' => true, 'message' => 'Acara berhasil dihapus'], 200);
    }

    public function updateInfaq(Request $request, $id)
    {
        $event = Event::findOrFail($id);
        $request->validate([
            'infaq' => 'required|numeric'
        ]);

        $event->infaq = $request->infaq;
        $event->save();

        return response()->json(['success' => true, 'message' => 'Infaq berhasil diperbarui', 'infaq' => $event->infaq], 200);
    }
}