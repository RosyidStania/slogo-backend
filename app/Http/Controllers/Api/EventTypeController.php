<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EventType;

class EventTypeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = EventType::orderBy('name', 'asc');
        
        // Jika yang login absen_kelompok, hanya tampilkan kategori yang di-set untuk absensi perkelompok
        if ($user && $user->role === 'absen_kelompok') {
            $query->where('is_group_attendance', true);
        }
        
        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:event_types,code|max:50',
            'start_time' => 'required',
            'target_kategori' => 'required|array',
            'description' => 'nullable|string',
            'is_group_attendance' => 'boolean'
        ]);

        $type = EventType::create([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'start_time' => $request->start_time,
            'target_kategori' => $request->target_kategori,
            'description' => $request->description,
            'is_group_attendance' => $request->is_group_attendance ?? false
        ]);

        return response()->json(['success' => true, 'data' => $type], 201);
    }

    public function update(Request $request, $id)
    {
        $type = EventType::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:event_types,code,' . $id,
            'start_time' => 'required',
            'target_kategori' => 'required|array',
            'description' => 'nullable|string',
            'is_group_attendance' => 'boolean'
        ]);

        $type->update([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'start_time' => $request->start_time,
            'target_kategori' => $request->target_kategori,
            'description' => $request->description,
            'is_group_attendance' => $request->is_group_attendance ?? false
        ]);

        return response()->json(['success' => true, 'data' => $type], 200);
    }

    public function destroy($id)
    {
        $type = EventType::findOrFail($id);
        $type->delete();
        return response()->json(['success' => true, 'message' => 'Jenis acara berhasil dihapus'], 200);
    }
}