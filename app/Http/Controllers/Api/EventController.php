<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\Generus;
use App\Services\ScheduledEventGenerator;
use App\Support\TargetKategoriFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    public function index(Request $request)
    {
        // Lazy generation fallback: pastikan jadwal rutin yang sudah masuk waktu otomatis terbuat & yang lewat 2 jam tertutup
        try {
            app(ScheduledEventGenerator::class)->run();
        } catch (\Throwable $e) {
            // Jangan sampai daftar acara gagal dimuat hanya karena generator error
            report($e);
        }

        $user = $request->user();
        
        $query = Event::withCount('attendances')->orderBy('event_date', 'desc')->orderBy('start_time', 'desc');
        
        // Jika user adalah MT, hanya tampilkan acara milik kelompoknya yang event_type-nya dicentang "Absensi Perkelompok"
        if ($user && $user->role === 'absen_kelompok') {
            if (empty($user->kelompok)) {
                return response()->json(['success' => true, 'data' => []], 200);
            }
            $query->whereRaw('LOWER(kelompok) = ?', [strtolower($user->kelompok)])
                  ->where(function ($q) {
                      $q->whereNotNull('group_schedule_id')
                        ->orWhereHas('eventType', function ($et) {
                            $et->where('is_group_attendance', true);
                        });
                  });
        }

        $events = $query->get();

        $generusQuery = Generus::select([
                'id',
                'kelompok',
                'jenjang',
                'status',
                'is_pengurus',
                'is_pengurus_muda_mudi',
                'is_ketua_wakil_kelompok',
            ])
            ->with('pengurusTypes:id,name,code')
            ->whereIn('status', ['aktif', 'pasif']);

        if ($user && $user->role === 'absen_kelompok') {
            $generusQuery->whereRaw('LOWER(kelompok) = ?', [strtolower($user->kelompok)]);
        }

        $allGenerus = $generusQuery->get();
        $generusByKelompok = $allGenerus->groupBy(fn($g) => strtolower($g->kelompok ?? ''));

        foreach ($events as $event) {
            $targetKategori = json_decode($event->target_kategori, true) ?: [];

            $filteredGenerus = !empty($event->kelompok)
                ? ($generusByKelompok->get(strtolower($event->kelompok)) ?? collect())
                : $allGenerus;

            $targetCount = TargetKategoriFilter::filter($filteredGenerus, $targetKategori)->count();
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
        $generusQuery = Generus::select([
                'id',
                'kelompok',
                'jenjang',
                'status',
                'is_pengurus',
                'is_pengurus_muda_mudi',
                'is_ketua_wakil_kelompok',
            ])
            ->with('pengurusTypes:id,name,code')
            ->whereIn('status', ['aktif', 'pasif']);

        if (!empty($event->kelompok)) {
            $generusQuery->whereRaw('LOWER(kelompok) = ?', [strtolower($event->kelompok)]);
        }

        $targetCount = TargetKategoriFilter::filter($generusQuery->get(), $targetKategori)->count();
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
        // Tandai auto_closed_at agar background auto-closer (ScheduledEventGenerator)
        // tidak langsung menutup kembali acara yang dibuka/diatur manual oleh operator
        $event->auto_closed_at = Carbon::now();
        $event->save();

        return response()->json([
            'success' => true,
            'message' => 'Status acara berhasil diubah',
            'is_closed' => (bool) $event->is_closed
        ], 200);
    }

    public function store(Request $request)
        {
            $request->validate([
                'name' => 'required|string|max:255',
                'event_date' => 'required|date',
                'start_time' => 'required',
                'end_time' => 'nullable',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id',
                'allow_other_participants' => 'boolean',
                'kelompok' => 'nullable|string|max:100',
                'infaq' => 'nullable|numeric'
            ]);

            $user = $request->user();
            $kelompok = ($user && $user->role === 'absen_kelompok') ? $user->kelompok : $request->kelompok;

            $event = Event::create([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time ?: null,
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
                'end_time' => 'nullable',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id',
                'allow_other_participants' => 'boolean',
                'infaq' => 'nullable|numeric'
            ]);

            $event->update([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                // Form edit tidak mengirim end_time; jangan hapus jam selesai acara otomatis
                'end_time' => $request->has('end_time') ? ($request->end_time ?: null) : $event->end_time,
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

        $user = $request->user();
        if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $request->validate([
            'infaq' => 'required|numeric'
        ]);

        $event->infaq = $request->infaq;
        $event->save();

        return response()->json(['success' => true, 'message' => 'Infaq berhasil diperbarui', 'infaq' => $event->infaq], 200);
    }
}