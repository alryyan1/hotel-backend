<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\InventoryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function exportPdf(InventoryReceipt $inventoryReceipt): Response
    {
        $inventoryReceipt->load(['user', 'items.inventory.category']);

        $pageWidth    = 210;
        $pageHeight   = 297;
        $leftMargin   = 15;
        $rightMargin  = 15;
        $topMargin    = 15;
        $bottomMargin = 15;

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('وارد مخزون #' . $inventoryReceipt->receipt_number);
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
        $pdf->Cell(0, 10, 'وارد مخزون', 0, 1, 'C');
        $pdf->Ln(5);

        // Receipt info
        $pdf->SetFont('arial', 'B', 11);
        $pdf->Cell(0, 7, 'معلومات الوارد', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(0, 6, 'رقم الوارد: ' . $inventoryReceipt->receipt_number, 0, 1, 'R');
        $pdf->Cell(0, 6, 'التاريخ: ' . date('d/m/Y', strtotime($inventoryReceipt->receipt_date)), 0, 1, 'R');
        if ($inventoryReceipt->supplier) {
            $pdf->Cell(0, 6, 'المورد: ' . $inventoryReceipt->supplier, 0, 1, 'R');
        }
        if ($inventoryReceipt->user) {
            $pdf->Cell(0, 6, 'المستلم: ' . $inventoryReceipt->user->name, 0, 1, 'R');
        }
        if ($inventoryReceipt->notes) {
            $pdf->Cell(0, 6, 'ملاحظات: ' . $inventoryReceipt->notes, 0, 1, 'R');
        }
        $pdf->Ln(5);

        // Items table
        $colCategory = 35;
        $colName     = 60;
        $colQty      = 30;
        $colPrice    = 30;
        $colTotal    = 25;

        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($colCategory, 8, 'الفئة',         1, 0, 'C', true);
        $pdf->Cell($colName,     8, 'الصنف',          1, 0, 'C', true);
        $pdf->Cell($colQty,      8, 'الكمية',         1, 0, 'C', true);
        $pdf->Cell($colPrice,    8, 'سعر الشراء',    1, 0, 'C', true);
        $pdf->Cell($colTotal,    8, 'الإجمالي',       1, 1, 'C', true);

        $pdf->SetFont('arial', '', 9);
        $grandTotal = 0;
        foreach ($inventoryReceipt->items as $item) {
            $lineTotal = ($item->purchase_price ?? 0) * $item->quantity_received;
            $grandTotal += $lineTotal;
            $pdf->Cell($colCategory, 7, $item->inventory->category->name ?? '-',      1, 0, 'R');
            $pdf->Cell($colName,     7, $item->inventory->name ?? '-',                1, 0, 'R');
            $pdf->Cell($colQty,      7, number_format($item->quantity_received, 2),   1, 0, 'C');
            $pdf->Cell($colPrice,    7, $item->purchase_price ? number_format($item->purchase_price, 0, '.', ',') : '-', 1, 0, 'C');
            $pdf->Cell($colTotal,    7, $lineTotal > 0 ? number_format($lineTotal, 0, '.', ',') : '-', 1, 1, 'C');
        }

        // Totals row
        if ($grandTotal > 0) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell($colCategory + $colName + $colQty + $colPrice, 8, 'الإجمالي الكلي', 1, 0, 'R', true);
            $pdf->Cell($colTotal, 8, number_format($grandTotal, 0, '.', ','), 1, 1, 'C', true);
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

        $filename = 'inventory_receipt_' . $inventoryReceipt->receipt_number . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
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
