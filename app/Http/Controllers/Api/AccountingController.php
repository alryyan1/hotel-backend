<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingController extends Controller
{
    /**
     * Get financial summary statistics
     */
    public function getSummary(Request $request): JsonResponse
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $summary = $this->getSummaryData($dateFrom, $dateTo);
        $summary['date_from'] = $dateFrom;
        $summary['date_to'] = $dateTo;

        return response()->json($summary);
    }

    /**
     * Get all transactions with filters
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $query = Transaction::with(['customer', 'reservation.rooms']);

        // Date filters
        if ($request->has('date_from') && $request->date_from) {
            $query->where('transaction_date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->where('transaction_date', '<=', $request->date_to);
        }

        // Type filter
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Customer filter
        if ($request->has('customer_id') && $request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        // Method filter (for credit transactions)
        if ($request->has('method') && $request->method) {
            $query->where('method', $request->method);
        }

        // Search by reference
        if ($request->has('search') && $request->search) {
            $query->where('reference', 'like', '%' . $request->search . '%');
        }

        $perPage = min($request->get('per_page', 20), 100);
        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Get customer balances summary
     */
    public function getCustomerBalances(Request $request): JsonResponse
    {
        $query = Customer::query();

        // Filter by balance (e.g., only customers with outstanding balances)
        if ($request->has('balance_filter')) {
            $balanceFilter = $request->balance_filter;
            if ($balanceFilter === 'outstanding') {
                // Only customers with balance > 0
                $query->whereHas('transactions', function ($q) {
                    // This will be filtered after calculation
                });
            }
        }

        $perPage = min($request->get('per_page', 20), 100);
        $customers = $query->orderBy('id', 'desc')->paginate($perPage);

        // Calculate balances for each customer
        $customers->getCollection()->transform(function ($customer) {
            $customer->load('transactions');

            $totalDebit = (float) $customer->transactions()
                ->where('type', 'debit')
                ->sum('amount');

            $totalCredit = (float) $customer->transactions()
                ->where('type', 'credit')
                ->sum('amount');

            $balance = $totalDebit - $totalCredit;

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'national_id' => $customer->national_id,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $balance,
            ];
        });

        // Filter by outstanding balance if requested
        if ($request->has('balance_filter') && $request->balance_filter === 'outstanding') {
            $customers->setCollection(
                $customers->getCollection()->filter(function ($customer) {
                    return $customer['balance'] > 0;
                })
            );
        }

        return response()->json($customers);
    }

    /**
     * Export accounting report as PDF
     */
    public function exportReportPdf(Request $request): Response
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        // Get summary
        $summary = $this->getSummaryData($dateFrom, $dateTo);

        // Get transactions
        $transactions = Transaction::with(['customer', 'reservation'])
            ->when($dateFrom, fn($q) => $q->where('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('transaction_date', '<=', $dateTo))
            ->orderBy('transaction_date', 'asc')
            ->get();

        // Get customer balances
        $customers = Customer::with('transactions')->get();
        $customerBalances = $customers->map(function ($customer) {
            $totalDebit = (float) $customer->transactions()->where('type', 'debit')->sum('amount');
            $totalCredit = (float) $customer->transactions()->where('type', 'credit')->sum('amount');
            return [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit,
            ];
        })->filter(fn($c) => $c['balance'] != 0)->values();

        // Create PDF
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('تقرير الحسابات');
        $pdf->SetSubject('Accounting Report');

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $leftMargin = 15;
        $rightMargin = 15;
        $topMargin = 15;
        $bottomMargin = 15;
        $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
        $pdf->SetAutoPageBreak(true, $bottomMargin);

        $pageWidth = 210;
        $pageHeight = 297;

        $pdf->setRTL(true);
        $pdf->AddPage();

        // Logo from Settings
        $settings = \App\Models\HotelSetting::first();
        $logoImagePath = null;
        if ($settings) {
            $logoPath = $settings->header_path ?? $settings->logo_path;
            if ($logoPath) {
                $logoImagePath = storage_path('app/public/' . $logoPath);
                if (!file_exists($logoImagePath)) {
                    $logoImagePath = public_path('storage/' . $logoPath);
                }
            }
        }

        if (file_exists($logoImagePath)) {
            try {
                $imageInfo = @getimagesize($logoImagePath);
                if ($imageInfo !== false) {
                    $maxLogoWidth = 25; // Smaller logo
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $logoWidthMM = $maxLogoWidth;
                    $logoHeightMM = $logoWidthMM * $aspectRatio;

                    $pdf->setRTL(false);
                    $xPos = ($pageWidth - $logoWidthMM) / 2;
                    $yPos = $topMargin;
                    $pdf->Image($logoImagePath, $xPos, $yPos, $logoWidthMM, $logoHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    $pdf->setRTL(true);
                    $pdf->SetY($yPos + $logoHeightMM + 5);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load logo image: ' . $e->getMessage());
            }
        }

        // Title
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 10, 'تقرير الحسابات', 0, 1, 'C');
        $pdf->Ln(5);

        // Date range
        if ($dateFrom || $dateTo) {
            $pdf->SetFont('arial', '', 10);
            $dateRange = 'الفترة: ';
            if ($dateFrom) $dateRange .= date('d/m/Y', strtotime($dateFrom));
            if ($dateFrom && $dateTo) $dateRange .= ' - ';
            if ($dateTo) $dateRange .= date('d/m/Y', strtotime($dateTo));
            $pdf->Cell(0, 6, $dateRange, 0, 1, 'R');
            $pdf->Ln(3);
        }

        // Summary
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'الملخص المالي', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $summaryInfo = [
            'إجمالي الإيرادات: ' . number_format($summary['total_revenue'], 0, '.', ',') . ' ',
            'إجمالي المصروفات: ' . number_format($summary['total_expenses'], 0, '.', ',') . ' ',
            'إجمالي المدين: ' . number_format($summary['total_debits'], 0, '.', ',') . ' ',
            'صافي الربح: ' . number_format($summary['net_profit'], 0, '.', ',') . ' ',
        ];

        foreach ($summaryInfo as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Transactions
        if ($transactions->count() > 0) {
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'العمليات المالية', 0, 1, 'R');
            $pdf->SetFont('arial', 'B', 9);
            $pdf->SetFillColor(230, 230, 230);

            $colDate = 30;
            $colType = 25;
            $colCustomer = 50;
            $colAmount = 35;
            $colMethod = 30;

            $pdf->Cell($colDate, 7, 'التاريخ', 1, 0, 'C', true);
            $pdf->Cell($colType, 7, 'النوع', 1, 0, 'C', true);
            $pdf->Cell($colCustomer, 7, 'العميل', 1, 0, 'C', true);
            $pdf->Cell($colAmount, 7, 'المبلغ', 1, 0, 'C', true);
            $pdf->Cell($colMethod, 7, 'الطريقة', 1, 1, 'C', true);

            $pdf->SetFont('arial', '', 8);
            foreach ($transactions as $transaction) {
                $pdf->setTextColor(255, 255, 255);
                
                // Color coding: Credit=Green, Debit=Red, Refund=Orange/Blue
                if ($transaction->type === 'credit') {
                    $pdf->setFillColor(0, 110, 0); // Green
                    $typeLabel = 'دائن';
                } elseif ($transaction->type === 'debit') {
                    $pdf->setFillColor(110, 0, 0); // Red
                    $typeLabel = 'مدين';
                } else {
                    $pdf->setFillColor(180, 90, 0); // Orange for Refund
                    $typeLabel = 'مسترجع';
                }

                $pdf->Cell($colDate, 6, date('d/m/Y', strtotime($transaction->transaction_date)), 1, 0, 'C', fill: true);
                $pdf->Cell($colType, 6, $typeLabel, 1, 0, 'C', fill: true);
                $pdf->Cell($colCustomer, 6, $transaction->customer->name ?? '-', 1, 0, 'C', fill: true);
                $pdf->Cell($colAmount, 6, number_format($transaction->amount, 0, '.', ','), 1, 0, 'C', fill: true);
                $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري'];
                $pdf->Cell($colMethod, 6, $methodLabels[$transaction->method] ?? '-', 1, 1, 'C', fill: true);
            }
            $pdf->setTextColor(0, 0, 0);
        }

        $pdf->Ln(5);

        // Customer Balances (Separate Page)
        if ($customerBalances->count() > 0) {
            $pdf->AddPage();
            
            $pdf->SetFont('arial', 'B', 15);
            $pdf->Cell(0, 10, 'تقرير أرصدة العملاء', 0, 1, 'C');
            $pdf->Ln(5);

            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'أرصدة العملاء', 0, 1, 'R');
            $pdf->SetFont('arial', 'B', 9);
            $pdf->SetFillColor(230, 230, 230);

            $colName = 60;
            $colPhone = 40;
            $colDebit = 30;
            $colCredit = 30;
            $colBalance = 30;

            $pdf->Cell($colName, 7, 'العميل', 1, 0, 'C', true);
            $pdf->Cell($colPhone, 7, 'الهاتف', 1, 0, 'C', true);
            $pdf->Cell($colDebit, 7, 'مدين', 1, 0, 'C', true);
            $pdf->Cell($colCredit, 7, 'دائن', 1, 0, 'C', true);
            $pdf->Cell($colBalance, 7, 'الرصيد', 1, 1, 'C', true);

            $pdf->SetFont('arial', '', 8);
            foreach ($customerBalances as $balance) {
                $pdf->Cell($colName, 6, $balance['name'], 1, 0, 'R');
                $pdf->Cell($colPhone, 6, $balance['phone'] ?? '-', 1, 0, 'C');
                $pdf->Cell($colDebit, 6, number_format($balance['total_debit'], 0, '.', ','), 1, 0, 'C');
                $pdf->Cell($colCredit, 6, number_format($balance['total_credit'], 0, '.', ','), 1, 0, 'C');
                $pdf->Cell($colBalance, 6, number_format($balance['balance'], 0, '.', ','), 1, 1, 'C');
            }
        }

        // Footer
        if ($settings && $settings->footer_path) {
            $footerImagePath = storage_path('app/public/' . $settings->footer_path);
            if (!file_exists($footerImagePath)) {
                $footerImagePath = public_path('storage/' . $settings->footer_path);
            }

            if (file_exists($footerImagePath)) {
            try {
                $imageInfo = @getimagesize($footerImagePath);
                if ($imageInfo !== false) {
                    $maxFooterWidth = $pageWidth * 0.9;
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $footerWidthMM = $maxFooterWidth;
                    $footerHeightMM = $footerWidthMM * $aspectRatio;

                    $currentY = $pdf->GetY();
                    if ($currentY + $footerHeightMM + $bottomMargin > $pageHeight - $bottomMargin) {
                        $pdf->AddPage();
                    }

                    $pdf->setRTL(false);
                    $xPos = ($pageWidth - $footerWidthMM) / 2;
                    $yPos = $pageHeight - $footerHeightMM - $bottomMargin;
                    $pdf->Image($footerImagePath, $xPos, $yPos, $footerWidthMM, $footerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    $pdf->setRTL(true);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load footer image: ' . $e->getMessage());
            }
        }
    }

    $filename = 'accounting_report_' . date('Y-m-d') . '.pdf';
    return response($pdf->Output($filename, 'S'), 200)
        ->header('Content-Type', 'application/pdf')
        ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
}

    /**
     * Export accounting report as Excel
     */
    public function exportReportExcel(Request $request): StreamedResponse
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $summary = $this->getSummaryData($dateFrom, $dateTo);
        $transactions = Transaction::with(['customer', 'reservation'])
            ->when($dateFrom, fn($q) => $q->where('transaction_date', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('transaction_date', '<=', $dateTo))
            ->orderBy('transaction_date', 'asc')
            ->get();

        $customers = Customer::with('transactions')->get();
        $customerBalances = $customers->map(function ($customer) {
            $totalDebit = (float) $customer->transactions()->where('type', 'debit')->sum('amount');
            $totalCredit = (float) $customer->transactions()->where('type', 'credit')->sum('amount');
            return [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit,
            ];
        })->filter(fn($c) => $c['balance'] != 0)->values();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->setActiveSheetIndex(0);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('تقرير الحسابات');
        $sheet->setRightToLeft(true);

        $row = 1;

        // Title
        $sheet->setCellValue('A' . $row, 'تقرير الحسابات');
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row += 2;

        // Summary
        $sheet->setCellValue('A' . $row, 'الملخص المالي');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;
        $sheet->setCellValue('A' . $row, 'إجمالي الإيرادات: ' . number_format($summary['total_revenue'], 2));
        $row++;
        $sheet->setCellValue('A' . $row, 'إجمالي المصروفات: ' . number_format($summary['total_expenses'], 2));
        $row++;
        $sheet->setCellValue('A' . $row, 'إجمالي المدين: ' . number_format($summary['total_debits'], 2));
        $row++;
        $sheet->setCellValue('A' . $row, 'صافي الربح: ' . number_format($summary['net_profit'], 2));
        $row += 2;

        // Transactions
        if ($transactions->count() > 0) {
            $sheet->setCellValue('A' . $row, 'العمليات المالية');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $headers = ['التاريخ', 'النوع', 'العميل', 'المبلغ', 'الطريقة', 'المرجع'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $row, $header);
                $sheet->getStyle($col . $row)->getFont()->setBold(true);
                $col++;
            }
            $row++;

            foreach ($transactions as $transaction) {
                $sheet->setCellValue('A' . $row, date('d/m/Y', strtotime($transaction->transaction_date)));
                $sheet->setCellValue('B' . $row, $transaction->type === 'debit' ? 'مدين' : 'دائن');
                $sheet->setCellValue('C' . $row, $transaction->customer->name ?? '-');
                $sheet->setCellValue('D' . $row, $transaction->amount);
                $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكاك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري'];
                $sheet->setCellValue('E' . $row, $methodLabels[$transaction->method] ?? '-');
                $sheet->setCellValue('F' . $row, $transaction->reference ?? '-');
                $row++;
            }
            $row++;
        }

        // Customer Balances
        if ($customerBalances->count() > 0) {
            $sheet->setCellValue('A' . $row, 'أرصدة العملاء');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $headers = ['العميل', 'الهاتف', 'مدين', 'دائن', 'الرصيد'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $row, $header);
                $sheet->getStyle($col . $row)->getFont()->setBold(true);
                $col++;
            }
            $row++;

            foreach ($customerBalances as $balance) {
                $sheet->setCellValue('A' . $row, $balance['name']);
                $sheet->setCellValue('B' . $row, $balance['phone'] ?? '-');
                $sheet->setCellValue('C' . $row, $balance['total_debit']);
                $sheet->setCellValue('D' . $row, $balance['total_credit']);
                $sheet->setCellValue('E' . $row, $balance['balance']);
                $row++;
            }
        }

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'accounting_report_' . date('Y-m-d_His') . '.xlsx';

        return new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Helper method to get summary data
     */
    public function exportNetBreakdownPdf(Request $request): Response
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $summary = $this->getSummaryData($dateFrom, $dateTo);

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('تقرير تفصيل الصافي');
        $pdf->SetSubject('Net Breakdown Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $leftMargin = 15;
        $rightMargin = 15;
        $topMargin = 15;
        $bottomMargin = 15;
        $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
        $pdf->SetAutoPageBreak(true, $bottomMargin);

        // Set RTL direction
        $pdf->setRTL(true);

        // Add a page
        $pdf->AddPage();

        $pageWidth = 210;
        // Title
        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 10, 'تقرير تفصيل الصافي حسب طريقة الدفع', 0, 1, 'C');
        
        if ($dateFrom || $dateTo) {
            $pdf->SetFont('arial', '', 10);
            $period = 'الفترة: ' . ($dateFrom ?: '...') . ' إلى ' . ($dateTo ?: '...');
            $pdf->Cell(0, 8, $period, 0, 1, 'C');
        }
        $pdf->Ln(5);

        // Table Header
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        $colMethod = 35;
        $colRooms = 30;
        $colServices = 30;
        $colExpenses = 30;
        $colRefunds = 25;
        $colNet = 30;

        $pdf->Cell($colMethod, 10, 'طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell($colRooms, 10, 'الايرادات', 1, 0, 'C', true);
        $pdf->Cell($colServices, 10, 'الخدمات', 1, 0, 'C', true);
        $pdf->Cell($colExpenses, 10, 'المصروف', 1, 0, 'C', true);
        $pdf->Cell($colRefunds, 10, 'مسترجع', 1, 0, 'C', true);
        $pdf->Cell($colNet, 10, 'الصافي', 1, 1, 'C', true);

        // Table Body
        $pdf->SetFont('arial', '', 10);
        $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري', 'unknown' => 'غير معروف'];
        
        $methods = array_unique(array_merge(
            array_keys($summary['revenue_by_method'] ?? []),
            array_keys($summary['services_by_method'] ?? []),
            array_keys($summary['expenses_by_method'] ?? []),
            array_keys($summary['refunds_by_method'] ?? [])
        ));

        foreach ($methods as $method) {
            $rev = $summary['revenue_by_method'][$method] ?? 0;
            $srv = $summary['services_by_method'][$method] ?? 0;
            $exp = $summary['expenses_by_method'][$method] ?? 0;
            $ref = $summary['refunds_by_method'][$method] ?? 0;
            $net = ($rev + $srv) - $exp - $ref;

            $pdf->Cell($colMethod, 8, $methodLabels[$method] ?? $method, 1, 0, 'C');
            $pdf->Cell($colRooms, 8, number_format($rev, 0, '.', ','), 1, 0, 'C');
            $pdf->Cell($colServices, 8, number_format($srv, 0, '.', ','), 1, 0, 'C');
            $pdf->Cell($colExpenses, 8, number_format($exp, 0, '.', ','), 1, 0, 'C');
            $pdf->Cell($colRefunds, 8, number_format($ref, 0, '.', ','), 1, 0, 'C');
            $pdf->Cell($colNet, 8, number_format($net, 0, '.', ','), 1, 1, 'C');
        }

        // Totals Row
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Cell($colMethod, 8, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell($colRooms, 8, number_format($summary['total_revenue'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colServices, 8, number_format($summary['total_service_revenue'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colExpenses, 8, number_format($summary['total_expenses'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colRefunds, 8, number_format($summary['total_refunds'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colNet, 8, number_format($summary['net_profit'], 0, '.', ','), 1, 1, 'C', true);

        $filename = 'net_breakdown_' . date('Y-m-d') . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf');
    }

    private function getSummaryData(?string $dateFrom, ?string $dateTo): array
    {
        $transactionQuery = Transaction::query();
        $costQuery = Cost::query();

        if ($dateFrom) {
            $transactionQuery->where('transaction_date', '>=', $dateFrom);
            $costQuery->where('date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $transactionQuery->where('transaction_date', '<=', $dateTo);
            $costQuery->where('date', '<=', $dateTo);
        }

        // Total revenue (credit transactions)
        $totalRevenue = (float) $transactionQuery->clone()
            ->where('type', 'credit')
            ->sum('amount');

        // Payment method breakdown for revenue
        $revenueByMethod = $transactionQuery->clone()
            ->where('type', 'credit')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method => (float) $item->total];
            })
            ->toArray();

        // Total debits (reservation transactions)
        $totalDebits = (float) $transactionQuery->clone()
            ->where('type', 'debit')
            ->sum('amount');

        // Total refunds (early checkout refunds)
        $totalRefunds = (float) $transactionQuery->clone()
            ->where('type', 'refund')
            ->sum('amount');

        // Payment method breakdown for refunds
        $refundsByMethod = $transactionQuery->clone()
            ->where('type', 'refund')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method ?: 'cash' => (float) $item->total];
            })
            ->toArray();

        // Total expenses (costs)
        $totalExpenses = (float) $costQuery->clone()->sum('amount');

        // Payment method breakdown for expenses
        $expensesByMethod = $costQuery->clone()
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->payment_method ?: 'unknown' => (float) $item->total];
            })
            ->toArray();

        // Total service revenue
        $serviceQuery = ReservationService::query();
        if ($dateFrom) {
            $serviceQuery->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $serviceQuery->where('created_at', '<=', $dateTo);
        }

        // Get service revenue breakdown by method
        $serviceRevenueByMethod = $serviceQuery->clone()
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->payment_method ?: 'cash' => (float) $item->total];
            })
            ->toArray();

        // Total service revenue
        $totalServiceRevenue = (float) $serviceQuery->sum('amount');

        // Net profit (revenue + service revenue - expenses - refunds)
        $netProfit = $totalRevenue + $totalServiceRevenue - $totalExpenses - $totalRefunds;

        return [
            'total_revenue' => $totalRevenue,
            'total_service_revenue' => $totalServiceRevenue,
            'revenue_by_method' => $revenueByMethod,
            'services_by_method' => $serviceRevenueByMethod,
            'total_debits' => $totalDebits,
            'total_refunds' => $totalRefunds,
            'refunds_by_method' => $refundsByMethod,
            'total_expenses' => $totalExpenses,
            'expenses_by_method' => $expensesByMethod,
            'net_profit' => $netProfit,
        ];
    }

    public function getMonthlyReport(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $daysInMonth = (int) date('t', strtotime("$year-$month-01"));
        $report = [];

        // Initialize report array for each day
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $report[$date] = [
                'date' => $date,
                'revenue_total' => 0,
                'revenue_cash' => 0,
                'revenue_bank' => 0,
                'service_revenue' => 0,
                'expense_total' => 0,
                'expense_cash' => 0,
                'expense_bank' => 0,
                'refund_total' => 0,
                'net' => 0,
            ];
        }

        // Get revenue data (transactions of type 'credit')
        $revenues = Transaction::where('type', 'credit')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->select(
                DB::raw('DATE(transaction_date) as day'),
                DB::raw('SUM(amount) as total'),
                'method'
            )
            ->groupBy('day', 'method')
            ->get();

        foreach ($revenues as $rev) {
            $day = $rev->day;
            if (isset($report[$day])) {
                $report[$day]['revenue_total'] += (float) $rev->total;
                if ($rev->method === 'cash') {
                    $report[$day]['revenue_cash'] += (float) $rev->total;
                } else {
                    $report[$day]['revenue_bank'] += (float) $rev->total;
                }
            }
        }

        // Get service revenue data
        $services = ReservationService::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('day')
            ->get();

        foreach ($services as $srv) {
            $day = $srv->day;
            if (isset($report[$day])) {
                $report[$day]['service_revenue'] += (float) $srv->total;
                $report[$day]['revenue_total'] += (float) $srv->total;
            }
        }

        // Get expense data (costs)
        $expenses = Cost::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->select(
                DB::raw('DATE(date) as day'),
                DB::raw('SUM(amount) as total'),
                'payment_method'
            )
            ->groupBy('day', 'payment_method')
            ->get();

        foreach ($expenses as $exp) {
            $day = $exp->day;
            if (isset($report[$day])) {
                $report[$day]['expense_total'] += (float) $exp->total;
                if ($exp->payment_method === 'cash') {
                    $report[$day]['expense_cash'] += (float) $exp->total;
                } else {
                    $report[$day]['expense_bank'] += (float) $exp->total;
                }
            }
        }

        // Get refund data (transactions of type 'refund')
        $refunds = Transaction::where('type', 'refund')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->select(
                DB::raw('DATE(transaction_date) as day'),
                DB::raw('SUM(amount) as total'),
                'method'
            )
            ->groupBy('day', 'method')
            ->get();

        foreach ($refunds as $ref) {
            $day = $ref->day;
            if (isset($report[$day])) {
                $report[$day]['refund_total'] += (float) $ref->total;
                // Deduct from revenue as requested: "اخصم مبلغ الاسترجاع من الايرادات"
                $report[$day]['revenue_total'] -= (float) $ref->total;
                if ($ref->method === 'cash') {
                    $report[$day]['revenue_cash'] -= (float) $ref->total;
                } else {
                    $report[$day]['revenue_bank'] -= (float) $ref->total;
                }
            }
        }

        // Calculate net for each day
        foreach ($report as &$dayData) {
            $dayData['net'] = $dayData['revenue_total'] - $dayData['expense_total'];
        }

        return response()->json([
            'report' => array_values($report),
            'year' => $year,
            'month' => $month,
        ]);
    }

    public function exportMonthlyReportPdf(Request $request): Response
    {
        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        // Get data directly to avoid JsonResponse overhead/issues
        $daysInMonth = (int) date('t', strtotime("$year-$month-01"));
        $report = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $report[$date] = [
                'date' => $date,
                'revenue_total' => 0,
                'revenue_cash' => 0,
                'revenue_bank' => 0,
                'service_revenue' => 0,
                'expense_total' => 0,
                'expense_cash' => 0,
                'expense_bank' => 0,
                'refund_total' => 0,
                'net' => 0,
            ];
        }

        $revenues = Transaction::where('type', 'credit')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->select(DB::raw('DATE(transaction_date) as day'), DB::raw('SUM(amount) as total'), 'method')
            ->groupBy('day', 'method')->get();

        foreach ($revenues as $rev) {
            $day = $rev->day;
            if (isset($report[$day])) {
                $report[$day]['revenue_total'] += (float) $rev->total;
                if ($rev->method === 'cash') $report[$day]['revenue_cash'] += (float) $rev->total;
                else $report[$day]['revenue_bank'] += (float) $rev->total;
            }
        }

        $expenses = Cost::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->select(DB::raw('DATE(date) as day'), DB::raw('SUM(amount) as total'), 'payment_method')
            ->groupBy('day', 'payment_method')->get();

        foreach ($expenses as $exp) {
            $day = $exp->day;
            if (isset($report[$day])) {
                $report[$day]['expense_total'] += (float) $exp->total;
                if ($exp->payment_method === 'cash') $report[$day]['expense_cash'] += (float) $exp->total;
                else $report[$day]['expense_bank'] += (float) $exp->total;
            }
        }

        // Get service revenue data
        $services = ReservationService::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(amount) as total')
            )
            ->groupBy('day')
            ->get();

        foreach ($services as $srv) {
            $day = $srv->day;
            if (isset($report[$day])) {
                $report[$day]['service_revenue'] += (float) $srv->total;
                $report[$day]['revenue_total'] += (float) $srv->total;
            }
        }

        // Get refunds (transactions of type 'refund')
        $refunds = Transaction::where('type', 'refund')
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->select(DB::raw('DATE(transaction_date) as day'), DB::raw('SUM(amount) as total'), 'method')
            ->groupBy('day', 'method')->get();

        foreach ($refunds as $ref) {
            $day = $ref->day;
            if (isset($report[$day])) {
                $report[$day]['refund_total'] += (float) $ref->total;
                $report[$day]['revenue_total'] -= (float) $ref->total;
                if ($ref->method === 'cash') $report[$day]['revenue_cash'] -= (float) $ref->total;
                else $report[$day]['revenue_bank'] -= (float) $ref->total;
            }
        }

        foreach ($report as &$dayData) {
            $dayData['net'] = $dayData['revenue_total'] - $dayData['expense_total'];
        }

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('التقرير الشهري للإيرادات والمصروفات');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);
        $pdf->setRTL(true);
        $pdf->AddPage();

        // Logo from Settings
        $settings = \App\Models\HotelSetting::first();
        $logoImagePath = null;
        if ($settings) {
            $logoPath = $settings->header_path ?? $settings->logo_path;
            if ($logoPath) {
                $logoImagePath = storage_path('app/public/' . $logoPath);
                if (!file_exists($logoImagePath)) {
                    $logoImagePath = public_path('storage/' . $logoPath);
                }
            }
        }

        if (file_exists($logoImagePath)) {
            try {
                $imageInfo = @getimagesize($logoImagePath);
                if ($imageInfo !== false) {
                    $maxLogoWidth = 20; // Even smaller logo for landscape
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $logoWidthMM = $maxLogoWidth;
                    $logoHeightMM = $logoWidthMM * $aspectRatio;

                    $pdf->setRTL(false);
                    // Place it on the right side since it's landscape RTL
                    $xPos = 297 - 10 - $logoWidthMM;
                    $yPos = 10;
                    $pdf->Image($logoImagePath, $xPos, $yPos, $logoWidthMM, $logoHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                    $pdf->setRTL(true);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load logo image: ' . $e->getMessage());
            }
        }

        $pdf->SetFont('freeserif', 'B', 18);
        $months = ["يناير", "فبراير", "مارس", "أبريل", "مايو", "يونيو", "يوليو", "أغسطس", "سبتمبر", "أكتوبر", "نوفمبر", "ديسمبر"];
        $monthName = $months[$month - 1];
        $pdf->Cell(0, 15, "تقرير الإيرادات والمصروفات لشهر $monthName $year", 0, 1, 'C');
        $pdf->Ln(5);

        $pdf->SetFont('freeserif', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(22, 10, 'التاريخ', 1, 0, 'C', true);
        $pdf->Cell(28, 10, 'إجمالي الإيراد', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'نقدي', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'بنك', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'خدمات', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'استرجاع', 1, 0, 'C', true);
        $pdf->Cell(30, 10, 'إجمالي الصرف', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'نقدي', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'بنك', 1, 0, 'C', true);
        $pdf->Cell(25, 10, 'الصافي', 1, 1, 'C', true);

        $pdf->SetFont('freeserif', '', 10);
        $totals = ['rev' => 0, 'rev_c' => 0, 'rev_b' => 0, 'srv' => 0, 'ref' => 0, 'exp' => 0, 'exp_c' => 0, 'exp_b' => 0, 'net' => 0];
        foreach ($report as $day) {
            $pdf->Cell(22, 8, date('d-m-Y', strtotime($day['date'])), 1, 0, 'C');
            $pdf->Cell(28, 8, number_format($day['revenue_total'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['revenue_cash'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['revenue_bank'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['service_revenue'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['refund_total'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(30, 8, number_format($day['expense_total'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['expense_cash'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell(25, 8, number_format($day['expense_bank'], 0, '.', ','), 1, 0, 'C');
            $pdf->SetTextColor($day['net'] >= 0 ? 0 : 200, $day['net'] >= 0 ? 100 : 0, 0);
            $pdf->Cell(25, 8, number_format($day['net'], 0, '.', ','), 1, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);

            $totals['rev'] += $day['revenue_total'];
            $totals['rev_c'] += $day['revenue_cash'];
            $totals['rev_b'] += $day['revenue_bank'];
            $totals['srv'] += $day['service_revenue'];
            $totals['ref'] += $day['refund_total'];
            $totals['exp'] += $day['expense_total'];
            $totals['exp_c'] += $day['expense_cash'];
            $totals['exp_b'] += $day['expense_bank'];
            $totals['net'] += $day['net'];
        }
        $pdf->SetFont('freeserif', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(22, 10, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell(28, 10, number_format($totals['rev'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['rev_c'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['rev_b'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['srv'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['ref'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(30, 10, number_format($totals['exp'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['exp_c'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['exp_b'], 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(25, 10, number_format($totals['net'], 0, '.', ','), 1, 1, 'C', true);
        $pdf->Ln(5);

        $filename = "monthly_report_{$year}_{$month}.pdf";
        if (ob_get_length()) ob_clean();
        $content = $pdf->Output('', 'S');
        
        return response()->make($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
