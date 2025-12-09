<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class InventoryReceiptController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = InventoryReceipt::with(['user', 'items.inventory.category']);

        // Date filter
        if ($request->has('date_from') && $request->date_from) {
            $query->where('receipt_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->where('receipt_date', '<=', $request->date_to);
        }

        // Supplier filter
        if ($request->has('supplier') && $request->supplier) {
            $query->where('supplier', 'like', "%{$request->supplier}%");
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhere('supplier', 'like', "%{$search}%");
            });
        }

        $receipts = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($receipts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'receipt_date' => 'required|date',
                'supplier' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'items' => 'required|array|min:1',
                'items.*.inventory_id' => 'required|exists:inventory,id',
                'items.*.quantity_received' => 'required|numeric|min:0.01',
                'items.*.purchase_price' => 'nullable|numeric|min:0',
                'items.*.notes' => 'nullable|string|max:500',
            ]);

            DB::beginTransaction();

            $receipt = InventoryReceipt::create([
                'receipt_number' => InventoryReceipt::generateReceiptNumber(),
                'receipt_date' => $validated['receipt_date'],
                'supplier' => $validated['supplier'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                InventoryReceiptItem::create([
                    'inventory_receipt_id' => $receipt->id,
                    'inventory_id' => $item['inventory_id'],
                    'quantity_received' => $item['quantity_received'],
                    'purchase_price' => $item['purchase_price'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);

                // Update inventory quantity
                $inventory = \App\Models\Inventory::find($item['inventory_id']);
                if ($inventory) {
                    $quantityBefore = $inventory->quantity;
                    $quantityReceived = $item['quantity_received'];
                    $inventory->quantity += $quantityReceived;
                    $inventory->save();

                    // Record history
                    InventoryHistory::create([
                        'inventory_id' => $inventory->id,
                        'type' => 'add',
                        'quantity_change' => $quantityReceived,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $inventory->quantity,
                        'reference_type' => 'receipt',
                        'reference_id' => $receipt->id,
                        'notes' => $item['notes'] ?? null,
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            DB::commit();

            $receipt->load(['user', 'items.inventory.category']);

            return response()->json($receipt, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(InventoryReceipt $inventoryReceipt): JsonResponse
    {
        $inventoryReceipt->load(['user', 'items.inventory.category']);
        return response()->json($inventoryReceipt);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, InventoryReceipt $inventoryReceipt): JsonResponse
    {
        try {
            $validated = $request->validate([
                'receipt_date' => 'required|date',
                'supplier' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
            ]);

            $inventoryReceipt->update($validated);
            $inventoryReceipt->load(['user', 'items.inventory.category']);

            return response()->json($inventoryReceipt);
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
    public function destroy(InventoryReceipt $inventoryReceipt): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Reverse inventory quantities
            foreach ($inventoryReceipt->items as $item) {
                $inventory = $item->inventory;
                if ($inventory) {
                    $quantityBefore = $inventory->quantity;
                    $quantityToReverse = $item->quantity_received;
                    $inventory->quantity -= $quantityToReverse;
                    if ($inventory->quantity < 0) {
                        $inventory->quantity = 0;
                    }
                    $inventory->save();

                    // Record history
                    InventoryHistory::create([
                        'inventory_id' => $inventory->id,
                        'type' => 'deduct',
                        'quantity_change' => -$quantityToReverse,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $inventory->quantity,
                        'reference_type' => 'receipt',
                        'reference_id' => $inventoryReceipt->id,
                        'notes' => 'حذف وارد - عكس الكمية',
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            $inventoryReceipt->delete();

            DB::commit();

            return response()->json(['message' => 'Receipt deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
