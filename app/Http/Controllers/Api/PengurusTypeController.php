<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generus;
use App\Models\PengurusType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PengurusTypeController extends Controller
{
    /**
     * Tampilkan semua jenis pengurus beserta jumlah anggotanya
     */
    public function index()
    {
        $types = PengurusType::withCount('generus')
            ->orderBy('order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $types,
        ], 200);
    }

    /**
     * Tambah jenis pengurus baru
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:pengurus_types,name',
            'badge_color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'order'       => 'nullable|integer',
        ], [
            'name.required' => 'Nama jenis pengurus wajib diisi.',
            'name.unique'   => 'Nama jenis pengurus sudah ada.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', trim($request->name)));
        $code = $baseCode;
        $counter = 1;
        while (PengurusType::where('code', $code)->exists()) {
            $code = "{$baseCode}_{$counter}";
            $counter++;
        }

        $type = PengurusType::create([
            'name'        => trim($request->name),
            'code'        => $code,
            'badge_color' => $request->input('badge_color', 'emerald'),
            'description' => $request->input('description'),
            'is_active'   => $request->boolean('is_active', true),
            'order'       => $request->input('order', 0),
        ]);

        $type->loadCount('generus');

        return response()->json([
            'success' => true,
            'message' => "Jenis pengurus '{$type->name}' berhasil ditambahkan.",
            'data'    => $type,
        ], 201);
    }

    /**
     * Ambil detail jenis pengurus beserta anggotanya
     */
    public function show($id)
    {
        $type = PengurusType::with(['generus' => function ($q) {
            $q->select('generus.id', 'generus.nama_lengkap', 'generus.kelompok', 'generus.jenjang', 'generus.jenis_kelamin', 'generus.status')
              ->orderBy('generus.nama_lengkap', 'asc');
        }])->withCount('generus')->find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Jenis pengurus tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $type,
        ], 200);
    }

    /**
     * Update data jenis pengurus
     */
    public function update(Request $request, $id)
    {
        $type = PengurusType::find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255|unique:pengurus_types,name,' . $id,
            'badge_color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'order'       => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $type->update([
            'name'        => trim($request->name),
            'badge_color' => $request->input('badge_color', $type->badge_color),
            'description' => $request->input('description', $type->description),
            'is_active'   => $request->boolean('is_active', $type->is_active),
            'order'       => $request->input('order', $type->order),
        ]);

        $type->loadCount('generus');

        return response()->json([
            'success' => true,
            'message' => "Jenis pengurus '{$type->name}' berhasil diperbarui.",
            'data'    => $type,
        ], 200);
    }

    /**
     * Hapus jenis pengurus
     */
    public function destroy($id)
    {
        $type = PengurusType::withCount('generus')->find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        if (in_array($type->code, ['PENGURUS_USMAN', 'PENGURUS_MUDA_MUDI', 'KETUA_WAKIL_KELOMPOK'])) {
            return response()->json([
                'success' => false,
                'message' => "Jenis pengurus '{$type->name}' adalah pengurus sistem bawaan dan tidak dapat dihapus. Anda dapat mengubah data atau menonaktifkannya.",
            ], 422);
        }

        // Simpan code sebelum delete untuk update legacy flags
        $code = $type->code;
        $generusIds = $type->generus()->pluck('generus.id')->toArray();

        DB::transaction(function () use ($type, $code, $generusIds) {
            // Detach all members
            $type->generus()->detach();
            $type->delete();

            // Update legacy flags if matching
            if (!empty($generusIds)) {
                if ($code === 'PENGURUS_USMAN') {
                    Generus::whereIn('id', $generusIds)->update(['is_pengurus' => false]);
                } elseif ($code === 'PENGURUS_MUDA_MUDI') {
                    Generus::whereIn('id', $generusIds)->update(['is_pengurus_muda_mudi' => false]);
                } elseif ($code === 'KETUA_WAKIL_KELOMPOK') {
                    Generus::whereIn('id', $generusIds)->update(['is_ketua_wakil_kelompok' => false]);
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Jenis pengurus '{$type->name}' berhasil dihapus.",
        ], 200);
    }

    /**
     * Ambil daftar anggota yang memegang jenis pengurus ini
     */
    public function members($id)
    {
        $type = PengurusType::find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        $members = $type->generus()
            ->select('generus.id', 'generus.nama_lengkap', 'generus.kelompok', 'generus.jenjang', 'generus.jenis_kelamin', 'generus.status')
            ->orderBy('generus.nama_lengkap', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'type'    => $type,
                'members' => $members,
            ],
        ], 200);
    }

    /**
     * QUICK ADD: Menambahkan banyak anggota ke jenis pengurus sekaligus (Bulk Assign)
     */
    public function bulkAssign(Request $request, $id)
    {
        $type = PengurusType::find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'generus_ids'   => 'required|array|min:1',
            'generus_ids.*' => 'integer|exists:generus,id',
        ], [
            'generus_ids.required' => 'Pilih minimal satu orang untuk ditambahkan.',
            'generus_ids.min'      => 'Pilih minimal satu orang untuk ditambahkan.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $generusIds = array_unique($request->input('generus_ids'));

        DB::transaction(function () use ($type, $generusIds) {
            // attach syncWithoutDetaching
            $type->generus()->syncWithoutDetaching($generusIds);

            // Update legacy columns jika jenis pengurus ini sesuai
            if ($type->code === 'PENGURUS_USMAN') {
                Generus::whereIn('id', $generusIds)->update(['is_pengurus' => true]);
            } elseif ($type->code === 'PENGURUS_MUDA_MUDI') {
                Generus::whereIn('id', $generusIds)->update(['is_pengurus_muda_mudi' => true]);
            } elseif ($type->code === 'KETUA_WAKIL_KELOMPOK') {
                Generus::whereIn('id', $generusIds)->update(['is_ketua_wakil_kelompok' => true]);
            }
        });

        $updatedCount = count($generusIds);

        return response()->json([
            'success' => true,
            'message' => "Berhasil menambahkan {$updatedCount} orang ke dalam {$type->name}.",
            'data'    => [
                'total_members' => $type->generus()->count(),
            ],
        ], 200);
    }

    /**
     * Melepas anggota dari jenis pengurus (bisa satu atau banyak sekaligus)
     */
    public function removeMembers(Request $request, $id)
    {
        $type = PengurusType::find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'generus_ids'   => 'required|array|min:1',
            'generus_ids.*' => 'integer|exists:generus,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $generusIds = $request->input('generus_ids');

        DB::transaction(function () use ($type, $generusIds) {
            $type->generus()->detach($generusIds);

            // Update legacy columns
            if ($type->code === 'PENGURUS_USMAN') {
                Generus::whereIn('id', $generusIds)->update(['is_pengurus' => false]);
            } elseif ($type->code === 'PENGURUS_MUDA_MUDI') {
                Generus::whereIn('id', $generusIds)->update(['is_pengurus_muda_mudi' => false]);
            } elseif ($type->code === 'KETUA_WAKIL_KELOMPOK') {
                Generus::whereIn('id', $generusIds)->update(['is_ketua_wakil_kelompok' => false]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Berhasil melepas anggota dari {$type->name}.",
            'data'    => [
                'total_members' => $type->generus()->count(),
            ],
        ], 200);
    }

    /**
     * Ambil calon anggota yang belum memiliki jenis pengurus ini (untuk Quick Add Modal)
     */
    public function candidateMembers(Request $request, $id)
    {
        $type = PengurusType::find($id);
        if (!$type) {
            return response()->json(['success' => false, 'message' => 'Jenis pengurus tidak ditemukan.'], 404);
        }

        $assignedIds = $type->generus()->pluck('generus.id')->toArray();

        $query = Generus::whereNotIn('id', $assignedIds)
            ->whereIn('status', ['aktif', 'pasif'])
            ->select('id', 'nama_lengkap', 'kelompok', 'jenjang', 'jenis_kelamin', 'status');

        if ($request->filled('kelompok') && $request->kelompok !== 'Semua') {
            $query->where('kelompok', $request->kelompok);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('nama_lengkap', 'like', "%{$search}%");
        }

        $candidates = $query->orderBy('nama_lengkap', 'asc')->get();

        return response()->json([
            'success' => true,
            'data'    => $candidates,
        ], 200);
    }
}
