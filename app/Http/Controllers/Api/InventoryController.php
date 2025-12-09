<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inventory::with('category');

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('category', function ($categoryQuery) use ($search) {
                      $categoryQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Category filter
        if ($request->has('category') && $request->category) {
            $query->where('category_id', $request->category);
        }

        // Stock status filter
        if ($request->has('stock_status') && $request->stock_status) {
            switch ($request->stock_status) {
                case 'low_stock':
                    $query->whereRaw('quantity <= minimum_stock');
                    break;
                case 'out_of_stock':
                    $query->where('quantity', '<=', 0);
                    break;
                case 'in_stock':
                    $query->whereRaw('quantity > minimum_stock');
                    break;
            }
        }

        $inventory = $query->orderBy('name', 'asc')->get();
        
        return response()->json($inventory);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'category_id' => 'nullable|exists:inventory_categories,id',
                'quantity' => 'required|numeric|min:0',
                'minimum_stock' => 'nullable|numeric|min:0',
            ]);

            $inventory = Inventory::create($validated);
            $inventory->load('category');

            return response()->json($inventory, 201);
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
    public function show(Inventory $inventory): JsonResponse
    {
        $inventory->load('category');
        return response()->json($inventory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Inventory $inventory): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'category_id' => 'nullable|exists:inventory_categories,id',
                'quantity' => 'required|numeric|min:0',
                'minimum_stock' => 'nullable|numeric|min:0',
            ]);

            $inventory->update($validated);
            $inventory->load('category');

            return response()->json($inventory);
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
    public function destroy(Inventory $inventory): JsonResponse
    {
        $inventory->delete();
        return response()->json(['message' => 'Inventory item deleted successfully']);
    }

    /**
     * Update stock quantity (add or subtract)
     */
    public function updateStock(Request $request, Inventory $inventory): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity_change' => 'required|numeric',
                'notes' => 'nullable|string|max:1000',
            ]);

            $quantityBefore = $inventory->quantity;
            $quantityChange = $validated['quantity_change'];
            $newQuantity = $quantityBefore + $quantityChange;
            
            if ($newQuantity < 0) {
                return response()->json([
                    'message' => 'Cannot reduce stock below zero',
                    'errors' => ['quantity_change' => ['الكمية الناتجة لا يمكن أن تكون أقل من الصفر']]
                ], 422);
            }

            $inventory->quantity = $newQuantity;
            $inventory->save();

            // Record history
            InventoryHistory::create([
                'inventory_id' => $inventory->id,
                'type' => $quantityChange > 0 ? 'add' : 'deduct',
                'quantity_change' => $quantityChange,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $newQuantity,
                'reference_type' => 'manual',
                'reference_id' => null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            $inventory->load('category');

            return response()->json($inventory);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * Get inventory history
     */
    public function history(Inventory $inventory): JsonResponse
    {
        $history = InventoryHistory::where('inventory_id', $inventory->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json($history);
    }

    /**
     * Get low stock items
     */
    public function lowStock(): JsonResponse
    {
        $lowStockItems = Inventory::with('category')
            ->whereRaw('quantity <= minimum_stock')
            ->orderBy('quantity', 'asc')
            ->get();
        
        return response()->json($lowStockItems);
    }
}

