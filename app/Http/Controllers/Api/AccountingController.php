<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Cost;
use App\Models\Customer;
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

        // Total expenses (costs)
        $totalExpenses = (float) $costQuery->sum('amount');

        // Net profit (revenue - expenses)
        $netProfit = $totalRevenue - $totalExpenses;

        return response()->json([
            'total_revenue' => $totalRevenue,
            'revenue_by_method' => $revenueByMethod,
            'total_debits' => $totalDebits,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
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

        // Logo
        $logoImagePath = public_path('logo.png');
        if (file_exists($logoImagePath)) {
            try {
                $imageInfo = @getimagesize($logoImagePath);
                if ($imageInfo !== false) {
                    $maxLogoWidth = $pageWidth * 0.2;
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $logoWidthMM = $maxLogoWidth;
                    $logoHeightMM = $logoWidthMM * $aspectRatio;

                    $pdf->setRTL(false);
                    $xPos = ($pageWidth - $logoWidthMM) / 2;
                    $yPos = $topMargin;
                    $pdf->Image($logoImagePath, $xPos, $yPos, $logoWidthMM, $logoHeightMM, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
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
            $colRef = 30;

            $pdf->Cell($colDate, 7, 'التاريخ', 1, 0, 'C', true);
            $pdf->Cell($colType, 7, 'النوع', 1, 0, 'C', true);
            $pdf->Cell($colCustomer, 7, 'العميل', 1, 0, 'C', true);
            $pdf->Cell($colAmount, 7, 'المبلغ', 1, 0, 'C', true);
            $pdf->Cell($colMethod, 7, 'الطريقة', 1, 0, 'C', true);
            $pdf->Cell($colRef, 7, 'المرجع', 1, 1, 'C', true);

            $pdf->SetFont('arial', '', 8);
            foreach ($transactions as $transaction) {
                $pdf->Cell($colDate, 6, date('d/m/Y', strtotime($transaction->transaction_date)), 1, 0, 'C');
                $pdf->Cell($colType, 6, $transaction->type === 'debit' ? 'مدين' : 'دائن', 1, 0, 'C');
                $pdf->Cell($colCustomer, 6, $transaction->customer->name ?? '-', 1, 0, 'R');
                $pdf->Cell($colAmount, 6, number_format($transaction->amount, 0, '.', ','), 1, 0, 'C');
                $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكاك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري'];
                $pdf->Cell($colMethod, 6, $methodLabels[$transaction->method] ?? '-', 1, 0, 'C');
                $pdf->Cell($colRef, 6, $transaction->reference ?? '-', 1, 1, 'C');
            }
        }

        $pdf->Ln(5);

        // Customer Balances
        if ($customerBalances->count() > 0) {
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
        $footerImagePath = public_path('footer.png');
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
                    $pdf->Image($footerImagePath, $xPos, $yPos, $footerWidthMM, $footerHeightMM, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
                    $pdf->setRTL(true);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load footer image: ' . $e->getMessage());
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

        return new StreamedResponse(function() use ($writer) {
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

        $totalRevenue = (float) $transactionQuery->clone()->where('type', 'credit')->sum('amount');
        
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
        
        $totalDebits = (float) $transactionQuery->clone()->where('type', 'debit')->sum('amount');
        $totalExpenses = (float) $costQuery->sum('amount');
        $netProfit = $totalRevenue - $totalExpenses;

        return [
            'total_revenue' => $totalRevenue,
            'revenue_by_method' => $revenueByMethod,
            'total_debits' => $totalDebits,
            'total_expenses' => $totalExpenses,
            'net_profit' => $netProfit,
        ];
    }
}

