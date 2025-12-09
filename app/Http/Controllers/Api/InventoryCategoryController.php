<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class InventoryCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $categories = InventoryCategory::orderBy('name')->get();
        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:inventory_categories,name',
            ]);

            $category = InventoryCategory::create($validated);

            return response()->json($category, 201);
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
    public function show(InventoryCategory $inventoryCategory): JsonResponse
    {
        return response()->json($inventoryCategory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InventoryCategory $inventoryCategory): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:inventory_categories,name,' . $inventoryCategory->id,
            ]);

            $inventoryCategory->update($validated);

            return response()->json($inventoryCategory);
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
    public function destroy(InventoryCategory $inventoryCategory): JsonResponse
    {
        $inventoryCategory->delete();
        return response()->json(['message' => 'Inventory category deleted successfully']);
    }
}
