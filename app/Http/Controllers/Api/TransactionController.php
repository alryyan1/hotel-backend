<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Services\CustomerBalanceService;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::with(['customer', 'reservation']);

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('reservation_id')) {
            $query->where('reservation_id', $request->reservation_id);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')->paginate(20);

        return response()->json($transactions);
    }

    /**
     * Store a newly created credit transaction (payment).
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'required|in:cash,bankak,Ocash,fawri',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'notes' => 'nullable|string',
                'reference' => 'nullable|string|unique:transactions,reference',
            ]);

            // Generate reference if not provided
            if (!isset($validated['reference'])) {
                $validated['reference'] = 'TX-' . strtoupper(Str::random(8));
            }

            // Set default currency if not provided
            if (!isset($validated['currency'])) {
                $validated['currency'] = 'USD';
            }

            // Always create credit transactions from this endpoint
            $validated['type'] = 'credit';
            $validated['transaction_date'] = now();

            // Check customer balance before creating payment
            $customerBalanceService = new CustomerBalanceService();
            $customer = Customer::findOrFail($validated['customer_id']);
            $currentBalance = $customerBalanceService->calculate($customer);

            if ($validated['amount'] > $currentBalance['balance']) {
                return response()->json([
                    'message' => 'مبلغ الدفعة يتجاوز الرصيد المتاح',
                    'errors' => [
                        'amount' => [
                            'مبلغ الدفعة (' . number_format($validated['amount'], 2) . ') يتجاوز الرصيد المتاح (' . number_format($currentBalance['balance'], 2) . ')'
                        ]
                    ],
                    'current_balance' => $currentBalance['balance'],
                    'payment_amount' => $validated['amount']
                ], 422);
            }

            $transaction = Transaction::create($validated);

            return response()->json($transaction->load(['customer', 'reservation']), 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified transaction.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json($transaction->load(['customer', 'reservation']));
    }

    /**
     * Update the specified transaction.
     */
    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'sometimes|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'sometimes|in:cash,bankak,Ocash,fawri',
                'amount' => 'sometimes|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'notes' => 'nullable|string',
                'reference' => 'sometimes|string|unique:transactions,reference,' . $transaction->id,
                'transaction_date' => 'sometimes|date',
            ]);

            // If updating amount for a credit transaction, validate balance
            if (isset($validated['amount']) && $transaction->type === 'credit') {
                $customerBalanceService = new CustomerBalanceService();
                $customer = $transaction->customer;
                
                // Calculate current balance excluding this transaction
                $currentBalance = $customerBalanceService->calculate($customer);
                
                // Remove the old transaction amount from balance calculation
                $balanceWithoutThisTransaction = $currentBalance['balance'] + $transaction->amount;
                
                // Check if new amount exceeds available balance
                if ($validated['amount'] > $balanceWithoutThisTransaction) {
                    return response()->json([
                        'message' => 'مبلغ الدفعة يتجاوز الرصيد المتاح',
                        'errors' => [
                            'amount' => [
                                'مبلغ الدفعة (' . number_format($validated['amount'], 2) . ') يتجاوز الرصيد المتاح (' . number_format($balanceWithoutThisTransaction, 2) . ')'
                            ]
                        ],
                        'current_balance' => $balanceWithoutThisTransaction,
                        'payment_amount' => $validated['amount']
                    ], 422);
                }
            }

            $transaction->update($validated);

            return response()->json($transaction->load(['customer', 'reservation']));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified transaction.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        $transaction->delete();

        return response()->json(['message' => 'Transaction deleted successfully']);
    }

    /**
     * Get transactions for a specific customer.
     */
    public function getCustomerTransactions(Customer $customer): JsonResponse
    {
        $transactions = Transaction::where('customer_id', $customer->id)
            ->with(['reservation'])
            ->orderBy('transaction_date', 'asc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Generate paid invoice PDF for a transaction (payment).
     */
    public function exportInvoicePdf(Transaction $transaction): Response
    {
        // Load transaction with relations
        $transaction->load(['customer', 'reservation']);

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('إيصال دفع #' . ($transaction->reference ?? $transaction->id));
        $pdf->SetSubject('Payment Receipt');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins (15mm on each side) - same as ledger PDF
        $leftMargin = 15;
        $rightMargin = 15;
        $topMargin = 15;
        $bottomMargin = 15;
        $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
        $pdf->SetAutoPageBreak(true, $bottomMargin);

        // Page dimensions (A4)
        $pageWidth = 210; // A4 width in mm
        $pageHeight = 297; // A4 height in mm

        // Set RTL direction
        $pdf->setRTL(true);

        // Add a page
        $pdf->AddPage();

        // Add logo image at the top (small size)
        $logoImagePath = public_path('logo.png');
        if (file_exists($logoImagePath)) {
            try {
                // Get image dimensions
                $imageInfo = @getimagesize($logoImagePath);
                if ($imageInfo !== false) {
                    // Set small width for logo (20% of page width)
                    $maxLogoWidth = $pageWidth * 0.2;

                    // Calculate height maintaining aspect ratio
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $logoWidthMM = $maxLogoWidth;
                    $logoHeightMM = $logoWidthMM * $aspectRatio;

                    // Temporarily disable RTL for image positioning
                    $pdf->setRTL(false);

                    // Position at top center
                    $xPos = ($pageWidth - $logoWidthMM) / 2;
                    $yPos = $topMargin;

                    $pdf->Image($logoImagePath, $xPos, $yPos, $logoWidthMM, $logoHeightMM, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);

                    // Re-enable RTL
                    $pdf->setRTL(true);

                    // Move Y position down after logo
                    $pdf->SetY($yPos + $logoHeightMM + 5);
                }
            } catch (\Exception $e) {
                // Silently continue if image fails to load
                Log::warning('Failed to load logo image: ' . $e->getMessage());
            }
        }

        // Set font for Arabic text
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 10, 'إيصال دفع', 0, 1, 'C');
        $pdf->Ln(5);

        // Payment Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'معلومات الدفعة', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $methodLabels = [
            'cash' => 'نقدي',
            'bankak' => 'بنكاك',
            'Ocash' => 'أوكاش',
            'fawri' => 'فوري'
        ];

        $paymentInfo = [
            'الرقم المرجعي: ' . ($transaction->reference ?? $transaction->id),
            'تاريخ الدفع: ' . ($transaction->transaction_date ? $transaction->transaction_date->format('d/m/Y H:i') : ''),
            'طريقة الدفع: ' . ($methodLabels[$transaction->method] ?? $transaction->method),
            'المبلغ: ' . number_format($transaction->amount, 0, '.', ',') . ' ',
        ];

        foreach ($paymentInfo as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        if ($transaction->notes) {
            $pdf->Ln(2);
            $pdf->Cell(0, 6, 'ملاحظات: ' . $transaction->notes, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Customer Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'بيانات العميل', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $customerInfo = [
            'الاسم: ' . $transaction->customer->name,
            $transaction->customer->phone ? 'الهاتف: ' . $transaction->customer->phone : null,
            $transaction->customer->national_id ? 'الرقم الوطني: ' . $transaction->customer->national_id : null,
        ];

        foreach (array_filter($customerInfo) as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        // Reservation Information (if linked to a reservation)
        if ($transaction->reservation) {
            $pdf->Ln(5);
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'معلومات الحجز المرتبط', 0, 1, 'R');
            $pdf->SetFont('arial', '', 10);

            $reservationInfo = [
                'رقم الحجز: #' . $transaction->reservation->id,
                'تاريخ الوصول: ' . date('d/m/Y', strtotime($transaction->reservation->check_in_date)),
                'تاريخ المغادرة: ' . date('d/m/Y', strtotime($transaction->reservation->check_out_date)),
            ];

            foreach ($reservationInfo as $info) {
                $pdf->Cell(0, 6, $info, 0, 1, 'R');
            }
        }

        $pdf->Ln(10);

        // Payment Summary Box
        $pdf->SetFont('arial', 'B', 14);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 10, 'المبلغ المدفوع: ' . number_format($transaction->amount, 0, '.', ',') . ' ', 1, 1, 'C', true);

        // Add footer image at the bottom
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

        // Generate PDF and return as response
        $filename = 'transaction_receipt_' . ($transaction->reference ?? $transaction->id) . '_' . date('Y-m-d') . '.pdf';

        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename=\"' . $filename . '\"');
    }
}


