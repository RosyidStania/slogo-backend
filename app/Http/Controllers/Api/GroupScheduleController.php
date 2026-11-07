<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\GroupSchedule;
use App\Services\ScheduledEventGenerator;
use Illuminate\Http\Request;

class GroupScheduleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = GroupSchedule::with('eventType')
            ->orderBy('kelompok')
            ->orderBy('day_of_week')
            ->orderBy('start_time');

        if ($this->isKelompokOperator($user)) {
            if (empty($user->kelompok)) {
                return response()->json(['success' => true, 'data' => []], 200);
            }
            $query->whereRaw('LOWER(kelompok) = ?', [strtolower($user->kelompok)]);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $request->validate($this->rules($user));

        $kelompok = $this->isKelompokOperator($user) ? $user->kelompok : $request->kelompok;
        if (empty($kelompok)) {
            return response()->json(['success' => false, 'message' => 'Kelompok wajib diisi'], 422);
        }

        $eventType = $this->resolveGroupEventType($request->event_type_id);

        $targetKategori = $request->target_kategori;
        if (empty($targetKategori)) {
            $targetKategori = !empty($eventType?->target_kategori)
                ? $eventType->target_kategori
                : ScheduledEventGenerator::DEFAULT_TARGET_KATEGORI;
        }

        $schedule = GroupSchedule::create([
            'kelompok' => $kelompok,
            'day_of_week' => (int) $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'event_type_id' => $eventType?->id,
            'name' => $request->name ?: ($eventType?->name ?? 'Pengajian Muda Mudi Kelompok'),
            'target_kategori' => $targetKategori,
            'allow_other_participants' => $request->allow_other_participants ?? false,
            'lead_minutes' => $request->lead_minutes ?? 30,
            'auto_close_minutes' => $request->auto_close_minutes ?? 120,
            'is_active' => $request->is_active ?? true,
        ]);

        // Jika jadwal baru ini sedang masuk waktunya hari ini, acaranya langsung dibuat
        $this->runGenerator();

        return response()->json([
            'success' => true,
            'data' => $schedule->load('eventType'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $schedule = GroupSchedule::findOrFail($id);
        $user = $request->user();

        if (!$this->canManage($user, $schedule)) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $request->validate($this->rules($user));

        $kelompok = $this->isKelompokOperator($user) ? $user->kelompok : $request->kelompok;
        $eventType = $this->resolveGroupEventType($request->event_type_id ?? $schedule->event_type_id);

        $targetKategori = $request->target_kategori;
        if (empty($targetKategori)) {
            $targetKategori = $schedule->target_kategori ?: ($eventType?->target_kategori ?? ScheduledEventGenerator::DEFAULT_TARGET_KATEGORI);
        }

        $schedule->update([
            'kelompok' => $kelompok,
            'day_of_week' => (int) $request->day_of_week,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'event_type_id' => $eventType?->id,
            'name' => $request->name ?: $schedule->name,
            'target_kategori' => $targetKategori,
            'allow_other_participants' => $request->allow_other_participants ?? $schedule->allow_other_participants,
            'lead_minutes' => $request->lead_minutes ?? $schedule->lead_minutes,
            'auto_close_minutes' => $request->auto_close_minutes ?? $schedule->auto_close_minutes,
            'is_active' => $request->has('is_active') ? (bool) $request->is_active : $schedule->is_active,
        ]);

        $this->runGenerator();

        return response()->json([
            'success' => true,
            'data' => $schedule->load('eventType'),
        ], 200);
    }

    public function toggleActive(Request $request, $id)
    {
        $schedule = GroupSchedule::findOrFail($id);

        if (!$this->canManage($request->user(), $schedule)) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $schedule->is_active = !$schedule->is_active;
        $schedule->save();

        if ($schedule->is_active) {
            $this->runGenerator();
        }

        return response()->json([
            'success' => true,
            'message' => $schedule->is_active ? 'Jadwal diaktifkan' : 'Jadwal dinonaktifkan (diliburkan)',
            'is_active' => $schedule->is_active,
        ], 200);
    }

    public function destroy(Request $request, $id)
    {
        $schedule = GroupSchedule::findOrFail($id);

        if (!$this->canManage($request->user(), $schedule)) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        // Acara yang sudah terbentuk tetap disimpan (group_schedule_id menjadi NULL lewat FK)
        $schedule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jadwal rutin berhasil dihapus',
        ], 200);
    }

    private function rules($user): array
    {
        return [
            'kelompok' => $this->isKelompokOperator($user) ? 'nullable|string' : 'required|string|max:100',
            'day_of_week' => 'required|integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'end_time' => 'required|date_format:H:i,H:i:s|different:start_time',
            'event_type_id' => 'nullable|exists:event_types,id',
            'name' => 'nullable|string|max:255',
            'target_kategori' => 'nullable|array',
            'allow_other_participants' => 'boolean',
            'lead_minutes' => 'nullable|integer|min:0|max:1440',
            'auto_close_minutes' => 'nullable|integer|min:0|max:1440',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Jadwal kelompok wajib memakai jenis acara "Absensi Perkelompok",
     * kalau tidak, acaranya tidak akan tampil di halaman operator kelompok.
     */
    private function resolveGroupEventType($eventTypeId): ?EventType
    {
        if ($eventTypeId) {
            $type = EventType::where('id', $eventTypeId)->where('is_group_attendance', true)->first();
            if ($type) {
                return $type;
            }
        }

        return EventType::where('is_group_attendance', true)
            ->orderByRaw("CASE WHEN code = '8' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    private function isKelompokOperator($user): bool
    {
        return $user && $user->role === 'absen_kelompok';
    }

    private function canManage($user, GroupSchedule $schedule): bool
    {
        if (!$this->isKelompokOperator($user)) {
            return true;
        }

        return strcasecmp($schedule->kelompok ?? '', $user->kelompok ?? '') === 0;
    }

    private function runGenerator(): void
    {
        try {
            app(ScheduledEventGenerator::class)->run();
        } catch (\Throwable $e) {
            // Jadwal tetap tersimpan; acara akan dibuat oleh cron / saat halaman acara dibuka
            report($e);
        }
    }
}
