<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $rooms = Room::with(['floor', 'type', 'status'])->get();
        return response()->json($rooms);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'number' => 'required|string|max:10|unique:rooms,number',
                'floor_id' => 'required|exists:floors,id',
                'room_type_id' => 'required|exists:room_types,id',
                'room_status_id' => 'required|exists:room_statuses,id',
                'beds' => 'required|integer|min:1|max:10',
                'notes' => 'nullable|string|max:1000',
            ]);

            $room = Room::create($validated);
            $room->load(['floor', 'type', 'status']);

            return response()->json($room, 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Room $room): JsonResponse
    {
        $room->load(['floor', 'type', 'status']);
        return response()->json($room);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Room $room): JsonResponse
    {
        try {
            $validated = $request->validate([
                'number' => 'required|string|max:10|unique:rooms,number,' . $room->id,
                'floor_id' => 'required|exists:floors,id',
                'room_type_id' => 'required|exists:room_types,id',
                'room_status_id' => 'required|exists:room_statuses,id',
                'beds' => 'required|integer|min:1|max:10',
                'notes' => 'nullable|string|max:1000',
            ]);

            $room->update($validated);
            $room->load(['floor', 'type', 'status']);

            return response()->json($room);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Room $room): JsonResponse
    {
        // Check if room has active reservations
        if ($room->reservations()->where('status', '!=', 'cancelled')->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete room with active reservations'
            ], 422);
        }

        $room->delete();
        return response()->json(['message' => 'Room deleted successfully']);
    }
}