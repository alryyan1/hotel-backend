<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function all(Request $request): JsonResponse
    {
        $query = Room::with(['floor', 'type']);

        $checkIn  = $request->query('check_in_date');
        $checkOut = $request->query('check_out_date');
        $excludeReservationId = $request->query('exclude_reservation_id');

        if ($checkIn && $checkOut) {
            $query->whereDoesntHave('reservations', function ($q) use ($checkIn, $checkOut, $excludeReservationId) {
                if ($excludeReservationId) {
                    $q->where('reservations.id', '<>', $excludeReservationId);
                }
                $q->where(function ($p) use ($checkIn, $checkOut) {
                    $p->where(function ($x) use ($checkIn, $checkOut) {
                        $x->where('reservation_room.check_in_date', '<', $checkOut)
                          ->where('reservation_room.check_out_date', '>', $checkIn);
                    })->orWhere(function ($x) use ($checkIn, $checkOut) {
                        $x->whereNull('reservation_room.check_in_date')
                          ->whereNull('reservation_room.check_out_date')
                          ->where('reservations.check_in_date', '<', $checkOut)
                          ->where('reservations.check_out_date', '>', $checkIn);
                    });
                });
            });
        }

        return response()->json($query->get());
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $rooms = Room::with(['floor', 'type', 'status'])->get();
        
        // Group rooms by floor
        $roomsByFloor = [];
        foreach ($rooms as $room) {
            $floorId = $room->floor_id ?? ($room->floor?->id ?? 'no-floor');
            
            if (!isset($roomsByFloor[$floorId])) {
                $roomsByFloor[$floorId] = [
                    'floor' => $room->floor ? [
                        'id' => $room->floor->id,
                        'number' => $room->floor->number,
                        'name' => $room->floor->name,
                    ] : null,
                    'rooms' => []
                ];
            }
            
            $roomsByFloor[$floorId]['rooms'][] = $room;
        }
        
        // Convert to array format for JSON response
        $groupedRooms = array_values($roomsByFloor);
        
        return response()->json($groupedRooms);
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