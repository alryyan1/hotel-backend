<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cost;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CostController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $costs = Cost::orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json($costs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'date' => 'required|date',
                'category' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
            ]);

            $cost = Cost::create($validated);

            return response()->json($cost, 201);
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
    public function show(Cost $cost): JsonResponse
    {
        return response()->json($cost);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Cost $cost): JsonResponse
    {
        try {
            $validated = $request->validate([
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0',
                'date' => 'required|date',
                'category' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
            ]);

            $cost->update($validated);

            return response()->json($cost);
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
    public function destroy(Cost $cost): JsonResponse
    {
        $cost->delete();
        return response()->json(['message' => 'Cost deleted successfully']);
    }
}

