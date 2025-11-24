<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cost;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $costs = Cost::with('costCategory')
            ->orderBy('date', 'desc')
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
                'cost_category_id' => 'nullable|exists:cost_categories,id',
                'payment_method' => 'nullable|in:cash,bankak',
                'notes' => 'nullable|string|max:1000',
            ]);

            $cost = Cost::create($validated);
            $cost->load('costCategory');

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
        $cost->load('costCategory');
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
                'cost_category_id' => 'nullable|exists:cost_categories,id',
                'payment_method' => 'nullable|in:cash,bankak',
                'notes' => 'nullable|string|max:1000',
            ]);

            $cost->update($validated);
            $cost->load('costCategory');

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

    /**
     * Export costs to Excel
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $query = Cost::with('costCategory');

        // Filter by date range if provided
        if ($request->has('date_from') && $request->date_from) {
            $query->where('date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->where('date', '<=', $request->date_to);
        }

        $costs = $query->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set sheet title
        $sheet->setTitle('المصاريف');

        // Headers
        $headers = ['التاريخ', 'الوصف', 'الفئة', 'المبلغ', 'طريقة الدفع', 'الملاحظات'];
        $headerRow = 1;
        $col = 'A';

        foreach ($headers as $header) {
            $sheet->setCellValue($col . $headerRow, $header);
            $sheet->getStyle($col . $headerRow)->applyFromArray([
                'font' => ['color' => ['rgb' => 'FFFFFF'], 'bold' => true, 'size' => 12],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ]);
            $col++;
        }

        // Data rows
        $row = 2;
        foreach ($costs as $cost) {
            $sheet->setCellValue('A' . $row, $cost->date ? date('Y-m-d', strtotime($cost->date)) : '');
            $sheet->setCellValue('B' . $row, $cost->description ?? '');
            $sheet->setCellValue('C' . $row, $cost->costCategory ? $cost->costCategory->name : '');
            $sheet->setCellValue('D' . $row, number_format($cost->amount, 2));
            $sheet->setCellValue('E' . $row, $cost->payment_method === 'cash' ? 'نقد' : ($cost->payment_method === 'bankak' ? 'بنك' : ($cost->payment_method ?? '')));
            $sheet->setCellValue('F' . $row, $cost->notes ?? '');

            // Style data cells
            $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Set header row height
        $sheet->getRowDimension(1)->setRowHeight(25);

        $filename = 'costs_export_' . date('Y-m-d_His') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        
        return new StreamedResponse(function() use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}


