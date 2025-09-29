<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FloorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $floors = Floor::withCount('rooms')->get();
        return response()->json($floors);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'number' => 'required|integer|unique:floors,number',
                'name' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
            ]);

            $floor = Floor::create($validated);
            $floor->loadCount('rooms');

            return response()->json($floor, 201);
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
    public function show(Floor $floor): JsonResponse
    {
        $floor->loadCount('rooms');
        return response()->json($floor);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Floor $floor): JsonResponse
    {
        try {
            $validated = $request->validate([
                'number' => 'required|integer|unique:floors,number,' . $floor->id,
                'name' => 'nullable|string|max:255',
                'description' => 'nullable|string|max:1000',
            ]);

            $floor->update($validated);
            $floor->loadCount('rooms');

            return response()->json($floor);
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
    public function destroy(Floor $floor): JsonResponse
    {
        // Check if floor has rooms
        if ($floor->rooms()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete floor with existing rooms'
            ], 422);
        }

        $floor->delete();
        return response()->json(['message' => 'Floor deleted successfully']);
    }
}
