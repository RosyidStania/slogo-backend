<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\Attendance;
use Exception;

class AttendanceController extends Controller
{
    // Mengambil Data Rekapan
    public function summary($id)
    {
        try {
            $event = Event::find($id);
            if (!$event) {
                return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
            }

            // Ambil absensi + data generusnya
            $attendances = Attendance::with('generus')
                            ->where('event_id', $id)
                            ->orderBy('created_at', 'desc')
                            ->get();

            return response()->json([
                'success' => true,
                'event' => $event,
                'attendances' => $attendances
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
            foreach ($request->attendances as $att) {
                $generusId = $att['generus_id'] ?? null;
                
                if (!$generusId && !empty($att['kode_unik'])) {
                    $generus = \App\Models\Generus::where('kode_unik', $att['kode_unik'])->first();
                    if ($generus) {
                        $generusId = $generus->id;
                    }
                }

                if ($generusId) {
                    Attendance::updateOrCreate(
                        ['event_id' => $att['event_id'], 'generus_id' => $generusId],
                        ['status' => $att['status'], 'time_arrived' => $att['time_arrived'], 'is_late' => $att['is_late']]
                    );
                }
            }
            return response()->json(['success' => true], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Beberapa data gagal disimpan. Periksa validitas referensi event/generus.'], 422);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // Hapus Absensi
    public function destroy($eventId, $generusId)
    {
        try {
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
    public function exportCsv($eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Acara tidak ditemukan'], 404);
        }

        $fileName = 'rekapan_absensi_' . str_replace(' ', '_', strtolower($event->nama_event)) . '_' . date('Ymd_His') . '.csv';
        $attendances = Attendance::with('generus')->where('event_id', $eventId)->get();

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['ID Acara', 'Kode Unik Peserta', 'ID Peserta (Abaikan saat import)', 'Nama Lengkap', 'Status Kehadiran', 'Waktu Datang', 'Terlambat'];

        $callback = function() use($attendances, $event, $columns) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF");
            fputcsv($file, $columns, ';');

            foreach ($attendances as $att) {
                fputcsv($file, [
                    $att->event_id,
                    $att->generus ? $att->generus->kode_unik : '',
                    $att->generus_id,
                    $att->generus ? $att->generus->nama_lengkap : '',
                    $att->status,
                    $att->time_arrived,
                    $att->is_late ? 'Ya' : 'Tidak'
                ], ';');
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}