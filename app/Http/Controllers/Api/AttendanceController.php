<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Attendance;
use App\Models\Generus;
use App\Support\TargetKategoriFilter;
use Illuminate\Support\Facades\DB;
use Exception;

class AttendanceController extends Controller
{
    // Mengambil Data Rekapan
    public function summary(Request $request, $id)
    {
        try {
            $event = Event::find($id);
            if (!$event) {
                return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
            }

            $user = $request->user();
            if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
            }

            // Ambil absensi + data generusnya
            $attendances = Attendance::with('generus')
                            ->where('event_id', $id)
                            ->orderBy('created_at', 'desc')
                            ->get();

            // Hitung target generus (filter kelompok di SQL jika acara khusus kelompok)
            $generusQuery = Generus::with('pengurusTypes:id,name,code')
                ->whereIn('status', ['aktif', 'pasif']);

            if (!empty($event->kelompok)) {
                $generusQuery->whereRaw('LOWER(kelompok) = ?', [strtolower($event->kelompok)]);
            }

            $allGenerus = $generusQuery->get();
            $targetKategori = json_decode($event->target_kategori, true) ?: [];
            $targetGenerus = TargetKategoriFilter::filter($allGenerus, $targetKategori);

            $previousEvent = null;
            if (!empty($event->event_type_id)) {
                $previousEvent = Event::where('event_type_id', $event->event_type_id)
                    ->where('kelompok', $event->kelompok)
                    ->where('id', '!=', $id)
                    ->where('event_date', '<', $event->event_date)
                    ->orderBy('event_date', 'desc')
                    ->first();
            }

            $previousAttendances = [];
            $previousTargetGenerus = collect();

            if ($previousEvent) {
                $previousAttendances = Attendance::with('generus')
                                ->where('event_id', $previousEvent->id)
                                ->get();
                
                $prevTargetKategori = json_decode($previousEvent->target_kategori, true) ?: [];
                $prevFilteredGenerus = !empty($previousEvent->kelompok)
                    ? $allGenerus->filter(fn($g) => strcasecmp($g->kelompok ?? '', $previousEvent->kelompok) === 0)->values()
                    : $allGenerus;

                $previousTargetGenerus = TargetKategoriFilter::filter($prevFilteredGenerus, $prevTargetKategori);
            }

            return response()->json([
                'success' => true,
                'event' => $event,
                'attendances' => $attendances,
                'target_generus' => $targetGenerus->values(),
                'previous_event' => $previousEvent,
                'previous_attendances' => $previousAttendances,
                'previous_target_generus' => $previousTargetGenerus->values()
            ], 200);

        } catch (Exception $e) {
            // Jika database/tabel bermasalah, akan memunculkan pesan ini, bukan sekadar error 500 kosong
            return response()->json([
                'success' => false, 
                'message' => 'Error Server: ' . $e->getMessage()
            ], 500);
        }
    }

    // Simpan Satu per Satu
    public function store(Request $request)
    {
        try {
            $event = Event::find($request->event_id);
            if (!$event) {
                return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
            }
            if ($event->is_closed) {
                return response()->json(['success' => false, 'message' => 'Acara ini sudah ditutup.'], 403);
            }
            $user = $request->user();
            if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
            }

            $attendance = Attendance::updateOrCreate(
                ['event_id' => $request->event_id, 'generus_id' => $request->generus_id],
                ['status' => $request->status, 'time_arrived' => $request->time_arrived, 'is_late' => $request->is_late]
            );
            return response()->json(['success' => true, 'data' => $attendance], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Gagal menyimpan data absensi. Pastikan Event atau Generus valid.'], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Simpan Massal
    public function bulkStore(Request $request)
    {
        try {
            $user = $request->user();
            $items = $request->attendances ?? [];
            if (empty($items)) {
                return response()->json(['success' => true], 200);
            }

            $eventIds = array_values(array_unique(array_filter(array_column($items, 'event_id'))));
            $eventsById = Event::whereIn('id', $eventIds)->get()->keyBy('id');

            $kodeUniks = [];
            foreach ($items as $att) {
                if (empty($att['generus_id']) && !empty($att['kode_unik'])) {
                    $kodeUniks[] = $att['kode_unik'];
                }
            }
            $generusIdsByKode = !empty($kodeUniks)
                ? Generus::whereIn('kode_unik', array_values(array_unique($kodeUniks)))->pluck('id', 'kode_unik')
                : collect();

            foreach ($items as $att) {
                $event = $eventsById->get($att['event_id'] ?? null);
                if (!$event) continue;
                if ($event->is_closed) {
                    return response()->json(['success' => false, 'message' => 'Acara ini sudah ditutup.'], 403);
                }
                if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                    return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
                }
            }

            DB::transaction(function () use ($items, $eventsById, $generusIdsByKode) {
                foreach ($items as $att) {
                    $event = $eventsById->get($att['event_id'] ?? null);
                    if (!$event) continue;

                    $generusId = $att['generus_id'] ?? null;
                    if (!$generusId && !empty($att['kode_unik'])) {
                        $generusId = $generusIdsByKode->get($att['kode_unik']);
                    }

                    if ($generusId) {
                        Attendance::updateOrCreate(
                            ['event_id' => $att['event_id'], 'generus_id' => $generusId],
                            ['status' => $att['status'], 'time_arrived' => $att['time_arrived'], 'is_late' => $att['is_late']]
                        );
                    }
                }
            });

            return response()->json(['success' => true], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Beberapa data gagal disimpan. Periksa validitas referensi event/generus.'], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Hapus Absensi
    public function destroy(Request $request, $eventId, $generusId)
    {
        try {
            $event = Event::find($eventId);
            if ($event && $event->is_closed) {
                return response()->json(['success' => false, 'message' => 'Acara ini sudah ditutup.'], 403);
            }
            $user = $request->user();
            if ($event && $user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
                return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
            }

            $deleted = Attendance::where('event_id', $eventId)
                                 ->where('generus_id', $generusId)
                                 ->delete();
            if ($deleted) {
                return response()->json(['success' => true], 200);
            }
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan'], 404);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Export Absensi
    public function exportCsv(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->role === 'absen_kelompok' && strcasecmp($event->kelompok ?? '', $user->kelompok ?? '') !== 0) {
            return response()->json(['success' => false, 'message' => 'Akses ditolak.'], 403);
        }

        $fileName = 'rekapan_absensi_' . str_replace(' ', '_', strtolower($event->name)) . '_' . date('Ymd_His') . '.csv';
        $attendances = Attendance::with('generus')->where('event_id', $eventId)->get();

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['ID Acara', 'Kode Unik Peserta', 'ID Peserta (Abaikan saat import)', 'Nama Lengkap', 'Status Kehadiran', 'Waktu Datang', 'Terlambat'];

        $sanitizeCsvCell = function ($value) {
            if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
                return "'" . $value;
            }
            return $value;
        };

        $callback = function() use($attendances, $event, $columns, $sanitizeCsvCell) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF");
            fputcsv($file, array_map($sanitizeCsvCell, $columns), ';');

            foreach ($attendances as $att) {
                $row = [
                    $att->event_id,
                    $att->generus ? $att->generus->kode_unik : '',
                    $att->generus_id,
                    $att->generus ? $att->generus->nama_lengkap : '',
                    $att->status,
                    $att->time_arrived,
                    $att->is_late ? 'Ya' : 'Tidak'
                ];
                fputcsv($file, array_map($sanitizeCsvCell, $row), ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}