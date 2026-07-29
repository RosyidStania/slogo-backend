<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class EventController extends Controller
{
    public function index()
    {
        $events = Event::orderBy('event_date', 'desc')->get();
        return response()->json(['success' => true, 'data' => $events], 200);
    }

    public function store(Request $request)
        {
            $request->validate([
                'name' => 'required|string|max:255',
                'event_date' => 'required|date',
                'start_time' => 'required',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id' // <-- TAMBAHAN BARU
            ]);

            $event = Event::create([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id // <-- TAMBAHAN BARU
            ]);

            return response()->json(['success' => true, 'data' => $event], 201);
        }

        public function update(Request $request, $id)
        {
            $event = Event::findOrFail($id);

            $request->validate([
                'name' => 'required|string|max:255',
                'event_date' => 'required|date',
                'start_time' => 'required',
                'target_kategori' => 'required|array',
                'event_type_id' => 'nullable|exists:event_types,id' // <-- TAMBAHAN BARU
            ]);

            $event->update([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id // <-- TAMBAHAN BARU
            ]);

            return response()->json(['success' => true, 'data' => $event], 200);
        }

    public function destroy($id)
    {
        $event = Event::find($id);
        if ($event) {
            $event->delete();
        }
        return response()->json(['success' => true, 'message' => 'Acara berhasil dihapus'], 200);
    }
}