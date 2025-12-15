<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Services\CustomerBalanceService;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['customer', 'reservation']);

        // Filter by customer if provided
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by reservation if provided
        if ($request->has('reservation_id')) {
            $query->where('reservation_id', $request->reservation_id);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($payments);
    }

    /**
     * Store a newly created payment.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'required|in:cash,bankak,Ocash,fawri',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3|default:USD',
                'status' => 'nullable|in:pending,completed,failed,refunded|default:completed',
                'notes' => 'nullable|string',
                'reference' => 'nullable|string|unique:payments,reference',
            ]);

            // Generate reference if not provided
            if (!isset($validated['reference'])) {
                $validated['reference'] = 'PAY-' . strtoupper(Str::random(8));
            }

            // Set default currency if not provided
            if (!isset($validated['currency'])) {
                $validated['currency'] = 'USD';
            }

            // Set default status if not provided
            if (!isset($validated['status'])) {
                $validated['status'] = 'completed';
            }
            $customerBalanceService = new CustomerBalanceService();
            $currentBalance = $customerBalanceService->calculate(Customer::find($validated['customer_id']));
            // Check customer balance before creating payment
            
            // Check if payment amount exceeds balance
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

            $payment = Payment::create($validated);
            return response()->json($payment->load(['customer', 'reservation']), 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified payment.
     */
    public function show(Payment $payment): JsonResponse
    {
        return response()->json($payment->load(['customer', 'reservation']));
    }

    /**
     * Update the specified payment.
     */
    public function update(Request $request, Payment $payment): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'sometimes|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'sometimes|in:cash,bankak,Ocash,fawri',
                'amount' => 'sometimes|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'status' => 'nullable|in:pending,completed,failed,refunded',
                'notes' => 'nullable|string',
                'reference' => 'sometimes|string|unique:payments,reference,' . $payment->id,
            ]);

            $payment->update($validated);
            return response()->json($payment->load(['customer', 'reservation']));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified payment.
     */
    public function destroy(Payment $payment): JsonResponse
    {
        $payment->delete();
        return response()->json(['message' => 'Payment deleted successfully']);
    }

    /**
     * Get payments for a specific customer.
     */
    public function getCustomerPayments(Customer $customer): JsonResponse
    {
        $payments = Payment::where('customer_id', $customer->id)
            ->with(['reservation'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json($payments);
    }

    /**
     * Generate paid invoice PDF for a payment
     */
    public function exportInvoicePdf(Payment $payment): Response
    {
        // Load payment with relations
        $payment->load(['customer', 'reservation']);

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('إيصال دفع #' . $payment->reference);
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
            'الرقم المرجعي: ' . $payment->reference,
            'تاريخ الدفع: ' . date('d/m/Y H:i', strtotime($payment->created_at)),
            'طريقة الدفع: ' . ($methodLabels[$payment->method] ?? $payment->method),
            'المبلغ: ' . number_format($payment->amount, 0, '.', ',') . ' ',
            // 'الحالة: ' . ($payment->status === 'completedظ' ? 'مكتمل' : $payment->status),
        ];

        foreach ($paymentInfo as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        if ($payment->notes) {
            $pdf->Ln(2);
            $pdf->Cell(0, 6, 'ملاحظات: ' . $payment->notes, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Customer Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'بيانات العميل', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $customerInfo = [
            'الاسم: ' . $payment->customer->name,
            $payment->customer->phone ? 'الهاتف: ' . $payment->customer->phone : null,
            $payment->customer->national_id ? 'الرقم الوطني: ' . $payment->customer->national_id : null,
        ];

        foreach (array_filter($customerInfo) as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        // Reservation Information (if linked to a reservation)
        if ($payment->reservation) {
            $pdf->Ln(5);
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'معلومات الحجز المرتبط', 0, 1, 'R');
            $pdf->SetFont('arial', '', 10);

            $reservationInfo = [
                'رقم الحجز: #' . $payment->reservation->id,
                'تاريخ الوصول: ' . date('d/m/Y', strtotime($payment->reservation->check_in_date)),
                'تاريخ المغادرة: ' . date('d/m/Y', strtotime($payment->reservation->check_out_date)),
            ];

            foreach ($reservationInfo as $info) {
                $pdf->Cell(0, 6, $info, 0, 1, 'R');
            }
        }

        $pdf->Ln(10);

        // Payment Summary Box
        $pdf->SetFont('arial', 'B', 14);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 10, 'المبلغ المدفوع: ' . number_format($payment->amount, 0, '.', ',') . ' ', 1, 1, 'C', true);

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
        $filename = 'payment_receipt_' . $payment->reference . '_' . date('Y-m-d') . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}