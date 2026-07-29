<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\EventType;

class EventTypeController extends Controller
{
    public function index()
    {
        return response()->json(['success' => true, 'data' => EventType::orderBy('name', 'asc')->get()], 200);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|unique:event_types,code|max:50',
            'start_time' => 'required',
            'target_kategori' => 'required|array',
            'description' => 'nullable|string'
        ]);

        $type = EventType::create([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'start_time' => $request->start_time,
            'target_kategori' => $request->target_kategori,
            'description' => $request->description
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
            'description' => 'nullable|string'
        ]);

        $type->update([
            'name' => $request->name,
            'code' => strtoupper($request->code),
            'start_time' => $request->start_time,
            'target_kategori' => $request->target_kategori,
            'description' => $request->description
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