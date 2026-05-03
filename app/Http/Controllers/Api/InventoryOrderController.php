<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryOrder;
use App\Models\InventoryOrderItem;
use App\Models\InventoryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function exportPdf(InventoryOrder $inventoryOrder): Response
    {
        $inventoryOrder->load(['user', 'items.inventory.category']);

        $pageWidth  = 210;
        $pageHeight = 297;
        $leftMargin = 15;
        $rightMargin = 15;
        $topMargin  = 15;
        $bottomMargin = 15;

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('طلب مخزون #' . $inventoryOrder->order_number);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
        $pdf->SetAutoPageBreak(true, $bottomMargin);
        $pdf->setRTL(true);
        $pdf->AddPage();

        $settings = \App\Models\HotelSetting::first();

        if ($settings && $settings->header_path) {
            $headerImagePath = storage_path('app/public/' . $settings->header_path);
            if (!file_exists($headerImagePath)) {
                $headerImagePath = public_path('storage/' . $settings->header_path);
            }
            if (file_exists($headerImagePath)) {
                try {
                    $imageInfo = @getimagesize($headerImagePath);
                    if ($imageInfo !== false) {
                        $headerWidthMM  = $pageWidth;
                        $headerHeightMM = $headerWidthMM * ($imageInfo[1] / $imageInfo[0]);
                        $pdf->setRTL(false);
                        $pdf->Image($headerImagePath, 0, 0, $headerWidthMM, $headerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                        $pdf->SetY($headerHeightMM + 5);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load header: ' . $e->getMessage());
                }
            }
        } elseif ($settings && $settings->logo_path) {
            $logoImagePath = storage_path('app/public/' . $settings->logo_path);
            if (!file_exists($logoImagePath)) {
                $logoImagePath = public_path('storage/' . $settings->logo_path);
            }
            if (file_exists($logoImagePath)) {
                try {
                    $imageInfo = @getimagesize($logoImagePath);
                    if ($imageInfo !== false) {
                        $logoWidthMM  = $pageWidth * 0.15;
                        $logoHeightMM = $logoWidthMM * ($imageInfo[1] / $imageInfo[0]);
                        $pdf->setRTL(false);
                        $xPos = ($pageWidth - $logoWidthMM) / 2;
                        $pdf->Image($logoImagePath, $xPos, $topMargin, $logoWidthMM, $logoHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                        $pdf->SetY($topMargin + $logoHeightMM + 5);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load logo: ' . $e->getMessage());
                }
            }
        }

        // Title
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 10, 'طلب صرف مخزون', 0, 1, 'C');
        $pdf->Ln(5);

        // Order info
        $statusLabels = ['pending' => 'قيد الانتظار', 'approved' => 'موافق عليه', 'rejected' => 'مرفوض', 'completed' => 'مكتمل'];
        $pdf->SetFont('arial', 'B', 11);
        $pdf->Cell(0, 7, 'معلومات الطلب', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(0, 6, 'رقم الطلب: ' . $inventoryOrder->order_number, 0, 1, 'R');
        $pdf->Cell(0, 6, 'التاريخ: ' . date('d/m/Y', strtotime($inventoryOrder->order_date)), 0, 1, 'R');
        $pdf->Cell(0, 6, 'الحالة: ' . ($statusLabels[$inventoryOrder->status] ?? $inventoryOrder->status), 0, 1, 'R');
        if ($inventoryOrder->user) {
            $pdf->Cell(0, 6, 'طالب الصرف: ' . $inventoryOrder->user->name, 0, 1, 'R');
        }
        if ($inventoryOrder->notes) {
            $pdf->Cell(0, 6, 'ملاحظات: ' . $inventoryOrder->notes, 0, 1, 'R');
        }
        $pdf->Ln(5);

        // Items table
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        $colCategory = 40;
        $colName     = 70;
        $colQty      = 35;
        $colNotes    = 35;

        $pdf->Cell($colCategory, 8, 'الفئة',      1, 0, 'C', true);
        $pdf->Cell($colName,     8, 'الصنف',       1, 0, 'C', true);
        $pdf->Cell($colQty,      8, 'الكمية',      1, 0, 'C', true);
        $pdf->Cell($colNotes,    8, 'ملاحظات',     1, 1, 'C', true);

        $pdf->SetFont('arial', '', 9);
        foreach ($inventoryOrder->items as $item) {
            $pdf->Cell($colCategory, 7, $item->inventory->category->name ?? '-', 1, 0, 'R');
            $pdf->Cell($colName,     7, $item->inventory->name ?? '-',            1, 0, 'R');
            $pdf->Cell($colQty,      7, number_format($item->quantity, 2),        1, 0, 'C');
            $pdf->Cell($colNotes,    7, $item->notes ?? '-',                      1, 1, 'R');
        }

        // Footer
        $pdf->SetAutoPageBreak(false);
        if ($settings && $settings->footer_path) {
            $footerImagePath = storage_path('app/public/' . $settings->footer_path);
            if (!file_exists($footerImagePath)) {
                $footerImagePath = public_path('storage/' . $settings->footer_path);
            }
            if (file_exists($footerImagePath)) {
                try {
                    $imageInfo = @getimagesize($footerImagePath);
                    if ($imageInfo !== false) {
                        $footerWidthMM  = $pageWidth;
                        $footerHeightMM = $footerWidthMM * ($imageInfo[1] / $imageInfo[0]);
                        $pdf->setRTL(false);
                        $pdf->Image($footerImagePath, 0, $pageHeight - $footerHeightMM, $footerWidthMM, $footerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load footer: ' . $e->getMessage());
                }
            }
        }
        $pdf->SetAutoPageBreak(true, $bottomMargin);

        $filename = 'inventory_order_' . $inventoryOrder->order_number . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
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
