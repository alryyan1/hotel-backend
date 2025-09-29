<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $rooms = Room::with(['floor', 'type', 'status'])->paginate(20);
        return response()->json($rooms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:50', 'unique:rooms,number'],
            'floor_id' => ['required', 'exists:floors,id'],
            'room_type_id' => ['required', 'exists:room_types,id'],
            'room_status_id' => ['required', 'exists:room_statuses,id'],
            'beds' => ['nullable', 'integer', 'min:0', 'max:10'],
            'notes' => ['nullable', 'string'],
        ]);

        $room = Room::create($data);
        return response()->json($room->load(['floor','type','status']), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room)
    {
        return response()->json($room->load(['floor','type','status']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'number' => ['sometimes', 'string', 'max:50', Rule::unique('rooms', 'number')->ignore($room->id)],
            'floor_id' => ['sometimes', 'exists:floors,id'],
            'room_type_id' => ['sometimes', 'exists:room_types,id'],
            'room_status_id' => ['sometimes', 'exists:room_statuses,id'],
            'beds' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'notes' => ['sometimes', 'string'],
        ]);

        $room->update($data);
        return response()->json($room->load(['floor','type','status']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Room $room)
    {
        $room->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
