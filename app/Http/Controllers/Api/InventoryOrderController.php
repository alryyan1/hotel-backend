<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryOrder;
use App\Models\InventoryOrderItem;
use App\Models\InventoryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class InventoryOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryOrder::with(['user', 'items.inventory.category']);

        // Status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Date filter
        if ($request->has('date_from') && $request->date_from) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->where('order_date', '<=', $request->date_to);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($orders);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_date' => 'required|date',
                'notes' => 'nullable|string|max:1000',
                'items' => 'required|array|min:1',
                'items.*.inventory_id' => 'required|exists:inventory,id',
                'items.*.quantity' => 'required|numeric|min:0.01',
                'items.*.notes' => 'nullable|string|max:500',
            ]);

            DB::beginTransaction();

            $order = InventoryOrder::create([
                'order_number' => InventoryOrder::generateOrderNumber(),
                'order_date' => $validated['order_date'],
                'status' => 'pending',
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                InventoryOrderItem::create([
                    'inventory_order_id' => $order->id,
                    'inventory_id' => $item['inventory_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            $order->load(['user', 'items.inventory.category']);

            return response()->json($order, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(InventoryOrder $inventoryOrder): JsonResponse
    {
        $inventoryOrder->load(['user', 'items.inventory.category']);
        return response()->json($inventoryOrder);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InventoryOrder $inventoryOrder): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,approved,rejected,completed',
                'notes' => 'nullable|string|max:1000',
            ]);

            $inventoryOrder->update($validated);
            $inventoryOrder->load(['user', 'items.inventory.category']);

            return response()->json($inventoryOrder);
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
    public function destroy(InventoryOrder $inventoryOrder): JsonResponse
    {
        $inventoryOrder->delete();
        return response()->json(['message' => 'Order deleted successfully']);
    }

    /**
     * Approve order and update inventory
     */
    public function approve(Request $request, InventoryOrder $inventoryOrder): JsonResponse
    {
        if ($inventoryOrder->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending orders can be approved'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // First, check if all items have enough stock
            foreach ($inventoryOrder->items as $item) {
                $inventory = $item->inventory;
                if (!$inventory) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Inventory item not found for order item ID: {$item->id}"
                    ], 422);
                }

                if ($inventory->quantity < $item->quantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Not enough stock for '{$inventory->name}'. Available: {$inventory->quantity}, Requested: {$item->quantity}"
                    ], 422);
                }
            }

            // If all checks pass, deduct quantities
            foreach ($inventoryOrder->items as $item) {
                $inventory = $item->inventory;
                $quantityBefore = $inventory->quantity;
                $quantityToDeduct = $item->quantity;
                $inventory->quantity -= $quantityToDeduct; // Subtract, not add
                if ($inventory->quantity < 0) {
                    $inventory->quantity = 0; // Ensure quantity doesn't go negative
                }
                $inventory->save();

                // Record history
                InventoryHistory::create([
                    'inventory_id' => $inventory->id,
                    'type' => 'deduct',
                    'quantity_change' => -$quantityToDeduct,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $inventory->quantity,
                    'reference_type' => 'order',
                    'reference_id' => $inventoryOrder->id,
                    'notes' => $item->notes ?? null,
                    'user_id' => $request->user()->id,
                ]);
            }

            $inventoryOrder->update(['status' => 'approved']);
            $inventoryOrder->load(['user', 'items.inventory.category']);

            DB::commit();

            return response()->json($inventoryOrder);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to approve order',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
