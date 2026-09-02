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
        $events = Event::withCount('attendances')->orderBy('event_date', 'desc')->get();
        
        $allGenerus = \App\Models\Generus::whereIn('status', ['aktif', 'pasif'])->get();

        foreach($events as $event) {
            $targetKategori = json_decode($event->target_kategori, true) ?: [];
            
            $targetCount = 0;
            if (empty($targetKategori)) {
                $targetCount = $allGenerus->count();
            } else {
                foreach($allGenerus as $g) {
                    $j = strtolower($g->jenjang ?? '');
                    $match = false;
                    foreach($targetKategori as $t) {
                        $tLower = strtolower($t);
                        if ($tLower === 'pengurus') {
                            if ($g->is_pengurus) $match = true;
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

    public function toggleStatus($id)
    {
        $event = Event::findOrFail($id);
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
                'allow_other_participants' => 'boolean'
            ]);

            $event = Event::create([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id,
                'allow_other_participants' => $request->allow_other_participants ?? false
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
                'event_type_id' => 'nullable|exists:event_types,id',
                'allow_other_participants' => 'boolean'
            ]);

            $event->update([
                'name' => $request->name,
                'event_date' => $request->event_date,
                'start_time' => $request->start_time,
                'target_kategori' => json_encode($request->target_kategori),
                'event_type_id' => $request->event_type_id,
                'allow_other_participants' => $request->allow_other_participants ?? false
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