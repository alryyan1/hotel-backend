<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CostCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $categories = CostCategory::orderBy('name')->get();
        return response()->json($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:cost_categories,name',
            ]);

            $category = CostCategory::create($validated);

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
    public function show(CostCategory $costCategory): JsonResponse
    {
        return response()->json($costCategory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CostCategory $costCategory): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:cost_categories,name,' . $costCategory->id,
            ]);

            $costCategory->update($validated);

            return response()->json($costCategory);
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
    public function destroy(CostCategory $costCategory): JsonResponse
    {
        $costCategory->delete();
        return response()->json(['message' => 'Cost category deleted successfully']);
    }
}

