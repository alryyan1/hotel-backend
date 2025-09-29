<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RoomStatusController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $roomStatuses = RoomStatus::withCount('rooms')->get();
        return response()->json($roomStatuses);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:10|unique:room_statuses,code',
                'name' => 'required|string|max:255',
                'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            ]);

            $roomStatus = RoomStatus::create($validated);
            $roomStatus->loadCount('rooms');

            return response()->json($roomStatus, 201);
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
    public function show(RoomStatus $roomStatus): JsonResponse
    {
        $roomStatus->loadCount('rooms');
        return response()->json($roomStatus);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RoomStatus $roomStatus): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:10|unique:room_statuses,code,' . $roomStatus->id,
                'name' => 'required|string|max:255',
                'color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            ]);

            $roomStatus->update($validated);
            $roomStatus->loadCount('rooms');

            return response()->json($roomStatus);
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
    public function destroy(RoomStatus $roomStatus): JsonResponse
    {
        // Check if room status has rooms
        if ($roomStatus->rooms()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete room status with existing rooms'
            ], 422);
        }

        $roomStatus->delete();
        return response()->json(['message' => 'Room status deleted successfully']);
    }
}
