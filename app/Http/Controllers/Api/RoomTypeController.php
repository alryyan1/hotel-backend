<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RoomTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $roomTypes = RoomType::withCount('rooms')->get();
        return response()->json($roomTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:10|unique:room_types,code',
                'name' => 'required|string|max:255',
                'capacity' => 'required|integer|min:1|max:10',
                'base_price' => 'required|numeric|min:0',
                'description' => 'nullable|string|max:1000',
            ]);

            $roomType = RoomType::create($validated);
            $roomType->loadCount('rooms');

            return response()->json($roomType, 201);
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
    public function show(RoomType $roomType): JsonResponse
    {
        $roomType->loadCount('rooms');
        return response()->json($roomType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:10|unique:room_types,code,' . $roomType->id,
                'name' => 'required|string|max:255',
                'capacity' => 'required|integer|min:1|max:10',
                'base_price' => 'required|numeric|min:0',
                'description' => 'nullable|string|max:1000',
            ]);

            $roomType->update($validated);
            $roomType->loadCount('rooms');

            return response()->json($roomType);
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
    public function destroy(RoomType $roomType): JsonResponse
    {
        // Check if room type has rooms
        if ($roomType->rooms()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete room type with existing rooms'
            ], 422);
        }

        $roomType->delete();
        return response()->json(['message' => 'Room type deleted successfully']);
    }
}
