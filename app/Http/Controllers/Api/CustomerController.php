<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = Customer::query()->orderByDesc('id')->paginate(20);
        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'national_id' => 'nullable|string|max:100|unique:customers,national_id',
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female',
            ]);

            $customer = Customer::create($validated);
            return response()->json($customer, 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'national_id' => 'nullable|string|max:100|unique:customers,national_id,' . $customer->id,
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female',
            ]);

            $customer->update($validated);
            return response()->json($customer);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function exportLedgerPdf(Customer $customer): Response
    {
        // Load customer with relations
        $customer->load(['reservations.rooms.type', 'payments']);

        // Get room types for pricing
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries
        $ledgerEntries = $this->calculateLedger($customer->reservations, $customer->payments, $roomTypes);

        // Create PDF
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('كشف حساب العميل - ' . $customer->name);
        $pdf->SetSubject('Customer Ledger Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);

        // Add a page
        $pdf->AddPage();

        // Set font for Arabic text
        $pdf->SetFont('dejavusans', '', 10);

        // Title
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->Cell(0, 10, 'كشف حساب العميل', 0, 1, 'C');
        $pdf->Ln(5);

        // Customer Information
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'بيانات العميل', 0, 1, 'R');
        $pdf->SetFont('dejavusans', '', 10);
        
        $customerInfo = [
            'الاسم: ' . $customer->name,
            $customer->phone ? 'الهاتف: ' . $customer->phone : null,
            $customer->national_id ? 'الرقم الوطني: ' . $customer->national_id : null,
        ];
        
        foreach (array_filter($customerInfo) as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }
        
        $pdf->Ln(5);

        // Ledger Table Header
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        
        $pdf->Cell(25, 8, 'التاريخ', 1, 0, 'C', true);
        $pdf->Cell(60, 8, 'الوصف', 1, 0, 'C', true);
        $pdf->Cell(40, 8, 'الغرف / طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'الأيام', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'مدين', 1, 0, 'C', true);
        $pdf->Cell(20, 8, 'دائن', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'الرصيد', 1, 1, 'C', true);

        // Ledger Entries
        $pdf->SetFont('dejavusans', '', 9);
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($ledgerEntries as $entry) {
            $totalDebit += $entry['debit'];
            $totalCredit += $entry['credit'];

            $pdf->Cell(25, 7, $entry['date'], 1, 0, 'C');
            $pdf->Cell(60, 7, $entry['description'], 1, 0, 'R');
            
            $details = $entry['type'] === 'reservation' 
                ? ($entry['rooms'] ?? '')
                : ($entry['paymentMethod'] ?? '');
            $pdf->Cell(40, 7, $details, 1, 0, 'C');
            
            $days = $entry['days'] ?? '-';
            $pdf->Cell(20, 7, $days, 1, 0, 'C');
            
            $debit = $entry['debit'] > 0 ? number_format($entry['debit'], 0, '.', ',') : '-';
            $pdf->Cell(20, 7, $debit, 1, 0, 'C');
            
            $credit = $entry['credit'] > 0 ? number_format($entry['credit'], 0, '.', ',') : '-';
            $pdf->Cell(20, 7, $credit, 1, 0, 'C');
            
            $balance = number_format($entry['balance'], 0, '.', ',');
            $pdf->Cell(25, 7, $balance, 1, 1, 'C');
        }

        // Totals Row
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(145, 8, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell(20, 8, number_format($totalDebit, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell(20, 8, number_format($totalCredit, 0, '.', ','), 1, 0, 'C', true);
        $finalBalance = $totalDebit - $totalCredit;
        $pdf->Cell(25, 8, number_format($finalBalance, 0, '.', ','), 1, 1, 'C', true);

        $pdf->Ln(5);

        // Final Balance
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(0, 8, 'الرصيد النهائي: ' . number_format($finalBalance, 0, '.', ','), 0, 1, 'R');

        // Generate PDF and return as response
        $filename = 'customer_ledger_' . $customer->id . '_' . date('Y-m-d') . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function calculateLedger($reservations, $payments, $roomTypes): array
    {
        $entries = [];
        $runningBalance = 0;

        // Combine reservations and payments, then sort by date
        $allEntries = [];

        foreach ($reservations as $reservation) {
            $allEntries[] = [
                'type' => 'reservation',
                'data' => $reservation,
                'date' => $reservation->check_in_date
            ];
        }

        foreach ($payments as $payment) {
            $allEntries[] = [
                'type' => 'payment',
                'data' => $payment,
                'date' => $payment->created_at
            ];
        }

        // Sort by date
        usort($allEntries, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        $methodLabels = [
            'cash' => 'نقدي',
            'bankak' => 'بنكاك',
            'Ocash' => 'أوكاش',
            'fawri' => 'فوري'
        ];

        foreach ($allEntries as $entry) {
            if ($entry['type'] === 'reservation') {
                $reservation = $entry['data'];
                $checkIn = new \DateTime($reservation->check_in_date);
                $checkOut = new \DateTime($reservation->check_out_date);
                $interval = $checkIn->diff($checkOut);
                $days = max(1, $interval->days);

                $totalDebit = 0;
                $roomNames = [];

                foreach ($reservation->rooms as $room) {
                    $basePrice = ($room->type && $room->type->base_price) 
                        ? $room->type->base_price 
                        : ($roomTypes[$room->room_type_id]->base_price ?? 0);
                    $roomDebit = $days * $basePrice;
                    $totalDebit += $roomDebit;
                    $roomNames[] = 'غرفة ' . $room->number;
                }

                $runningBalance += $totalDebit;

                $entries[] = [
                    'id' => $reservation->id,
                    'type' => 'reservation',
                    'date' => date('d/m/Y', strtotime($reservation->check_in_date)),
                    'description' => 'حجز #' . $reservation->id . ' - ' . implode(', ', $roomNames),
                    'rooms' => implode(', ', $roomNames),
                    'days' => $days,
                    'debit' => $totalDebit,
                    'credit' => 0,
                    'balance' => $runningBalance
                ];
            } else {
                $payment = $entry['data'];
                $runningBalance -= $payment->amount;

                $entries[] = [
                    'id' => $payment->id,
                    'type' => 'payment',
                    'date' => date('d/m/Y', strtotime($payment->created_at)),
                    'description' => 'دفعة - ' . ($payment->reference ?? ''),
                    'paymentMethod' => $methodLabels[$payment->method] ?? $payment->method,
                    'days' => null,
                    'debit' => 0,
                    'credit' => $payment->amount,
                    'balance' => $runningBalance
                ];
            }
        }

        return $entries;
    }
}


