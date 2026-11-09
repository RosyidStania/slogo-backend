<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\MeetingNote;
use App\Models\Event;
use App\Models\Attendance;

class MeetingNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = MeetingNote::with(['event:id,name,event_date,start_time,kelompok', 'creator:id,name'])
            ->orderBy('tanggal', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $s = '%' . trim($request->search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('judul', 'like', $s)
                  ->orWhere('agenda', 'like', $s)
                  ->orWhere('pembahasan', 'like', $s)
                  ->orWhere('pemimpin', 'like', $s)
                  ->orWhere('notulis', 'like', $s)
                  ->orWhere('lokasi', 'like', $s);
            });
        }

        if ($request->filled('event_id')) {
            $query->where('event_id', $request->event_id);
        }

        if ($request->filled('year')) {
            $query->whereYear('tanggal', $request->year);
        }

        $notes = $query->get();

        return response()->json([
            'success' => true,
            'data' => $notes
        ], 200);
    }

    public function eventsList(Request $request)
    {
        // Hanya ambil Acara Umum Desa (bukan acara perkelompok)
        $events = Event::with('eventType:id,name,is_group_attendance')
            ->where(function ($q) {
                $q->whereNull('kelompok')->orWhere('kelompok', '');
            })
            ->whereNull('group_schedule_id')
            ->where(function ($q) {
                $q->whereDoesntHave('eventType')
                  ->orWhereHas('eventType', function ($et) {
                      $et->where('is_group_attendance', false);
                  });
            })
            ->withCount(['attendances' => function ($q) {
                $q->where('status', 'hadir');
            }])
            ->orderBy('event_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->take(150)
            ->get(['id', 'name', 'event_date', 'start_time', 'kelompok', 'event_type_id']);

        return response()->json([
            'success' => true,
            'data' => $events
        ], 200);
    }

    public function getByEvent(Request $request, $eventId)
    {
        $event = Event::with('eventType:id,name')
            ->withCount(['attendances' => function ($q) {
                $q->where('status', 'hadir');
            }])
            ->find($eventId);

        if (!$event) {
            return response()->json([
                'success' => false,
                'message' => 'Acara tidak ditemukan'
            ], 404);
        }

        $note = MeetingNote::where('event_id', $eventId)
            ->with('creator:id,name')
            ->first();

        return response()->json([
            'success' => true,
            'event' => $event,
            'note' => $note
        ], 200);
    }

    public function show($id)
    {
        $note = MeetingNote::with(['event:id,name,event_date,start_time,kelompok', 'creator:id,name'])
            ->find($id);

        if (!$note) {
            return response()->json([
                'success' => false,
                'message' => 'Notulen tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $note
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'event_id' => 'required|exists:events,id',
            'judul' => 'nullable|string|max:255',
            'tanggal' => 'nullable|date',
            'waktu' => 'nullable|string|max:50',
            'lokasi' => 'nullable|string|max:255',
            'pemimpin' => 'nullable|string|max:255',
            'notulis' => 'nullable|string|max:255',
            'agenda' => 'nullable|string',
            'pembahasan' => 'nullable|string',
            'keputusan' => 'nullable|array',
            'peserta_hadir_count' => 'nullable|integer|min:0',
            'lampiran_info' => 'nullable|string'
        ]);

        $event = Event::findOrFail($validated['event_id']);

        // Cek jika sudah pernah dibuat notulen untuk event ini, langsung gunakan yang sudah ada
        $existing = MeetingNote::where('event_id', $event->id)->first();
        if ($existing) {
            $existing->load(['event:id,name,event_date,start_time,kelompok', 'creator:id,name']);
            return response()->json([
                'success' => true,
                'message' => 'Notulen untuk acara ini sudah ada',
                'data' => $existing
            ], 200);
        }

        $waktu = !empty($validated['waktu']) ? $validated['waktu'] : ($event->start_time ? substr($event->start_time, 0, 5) . ' WIB' : '19:30 WIB');
        $lokasi = !empty($validated['lokasi']) ? $validated['lokasi'] : (!empty($event->kelompok) ? 'Kelompok ' . $event->kelompok : 'Masjid Desa');
        $hadirCount = Attendance::where('event_id', $event->id)->where('status', 'hadir')->count();

        $note = MeetingNote::create([
            'event_id' => $event->id,
            'judul' => $validated['judul'] ?? $event->name,
            'tanggal' => $validated['tanggal'] ?? $event->event_date,
            'waktu' => $waktu,
            'lokasi' => $lokasi,
            'pemimpin' => $validated['pemimpin'] ?? null,
            'notulis' => $validated['notulis'] ?? null,
            'agenda' => $validated['agenda'] ?? null,
            'pembahasan' => $validated['pembahasan'] ?? '',
            'keputusan' => $validated['keputusan'] ?? [],
            'peserta_hadir_count' => $validated['peserta_hadir_count'] ?? $hadirCount,
            'lampiran_info' => $validated['lampiran_info'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $note->load(['event:id,name,event_date,start_time,kelompok', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Notulen musyawarah berhasil dibuat',
            'data' => $note
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $note = MeetingNote::findOrFail($id);

        $validated = $request->validate([
            'judul' => 'nullable|string|max:255',
            'tanggal' => 'nullable|date',
            'waktu' => 'nullable|string|max:50',
            'lokasi' => 'nullable|string|max:255',
            'pemimpin' => 'nullable|string|max:255',
            'notulis' => 'nullable|string|max:255',
            'agenda' => 'nullable|string',
            'pembahasan' => 'nullable|string',
            'keputusan' => 'nullable|array',
            'peserta_hadir_count' => 'nullable|integer|min:0',
            'lampiran_info' => 'nullable|string'
        ]);

        $note->update($validated);
        $note->load(['event:id,name,event_date,start_time,kelompok', 'creator:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Notulen musyawarah berhasil disimpan',
            'data' => $note
        ], 200);
    }

    public function destroy($id)
    {
        $note = MeetingNote::findOrFail($id);
        $note->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notulen musyawarah berhasil dihapus'
        ], 200);
    }
}
