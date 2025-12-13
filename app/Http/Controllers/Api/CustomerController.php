<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();
        
        // Search functionality
        if ($request->has('search') && $request->get('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('national_id', 'like', "%{$search}%");
            });
        }
        
        $perPage = min($request->get('per_page', 20), 100);
        $customers = $query->orderByDesc('id')->paginate($perPage);
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
        // Delete associated document if exists
        if ($customer->document_path && Storage::disk('public')->exists($customer->document_path)) {
            Storage::disk('public')->delete($customer->document_path);
        }
        
        $customer->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function uploadDocument(Request $request, Customer $customer): JsonResponse
    {
        try {
            $request->validate([
                'document' => 'required|file|mimes:pdf|max:10240', // Max 10MB
            ]);

            // Delete old document if exists
            if ($customer->document_path && Storage::disk('public')->exists($customer->document_path)) {
                Storage::disk('public')->delete($customer->document_path);
            }

            // Store the file
            $file = $request->file('document');
            $filename = 'customer_' . $customer->id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('documents/customers', $filename, 'public');

            // Update customer with document path
            $customer->update(['document_path' => $path]);

            return response()->json([
                'message' => 'Document uploaded successfully',
                'document_path' => $path,
                'document_url' => Storage::disk('public')->url($path),
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Document upload failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to upload document'], 500);
        }
    }

    public function deleteDocument(Customer $customer): JsonResponse
    {
        try {
            if ($customer->document_path && Storage::disk('public')->exists($customer->document_path)) {
                Storage::disk('public')->delete($customer->document_path);
            }

            $customer->update(['document_path' => null]);

            return response()->json(['message' => 'Document deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Document deletion failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete document'], 500);
        }
    }

    public function downloadDocument(Customer $customer): StreamedResponse|JsonResponse
    {
        if (!$customer->document_path || !Storage::disk('public')->exists($customer->document_path)) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        return Storage::disk('public')->download($customer->document_path);
    }

    public function getBalance(Customer $customer): JsonResponse
    {
        // Load customer with relations
        $customer->load(['reservations.rooms.type', 'payments']);

        // Get room types for pricing
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries
        $ledgerEntries = $this->calculateLedger($customer->reservations, $customer->payments, $roomTypes);

        // Calculate total debit and credit
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($ledgerEntries as $entry) {
            $totalDebit += $entry['debit'];
            $totalCredit += $entry['credit'];
        }

        $balance = $totalDebit - $totalCredit;

        return response()->json([
            'balance' => $balance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ]);
    }

    public function exportLedgerPdf(Customer $customer): Response
    {
        // Load customer with relations
        $customer->load(['reservations.rooms.type', 'payments']);

        // Get room types for pricing
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries
        $ledgerEntries = $this->calculateLedger($customer->reservations, $customer->payments, $roomTypes);

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('كشف حساب العميل - ' . $customer->name);
        $pdf->SetSubject('Customer Ledger Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Set margins (15mm on each side)
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
                    
                    // Temporarily disable RTL for image positioning (easier to calculate)
                    $pdf->setRTL(false);
                    
                    // Position at top center (simple center calculation)
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

        // Calculate available width (A4 width = 210mm, minus margins)
        $availableWidth = $pageWidth - $leftMargin - $rightMargin; // 180mm

        // Define column widths (proportioned to fit available width)
        $colDate = 22;      // التاريخ
        $colDescription = 51; // الوصف
        $colDetails = 34;    // الغرف / طريقة الدفع
        $colDays = 17;       // الأيام
        $colDebit = 17;      // مدين
        $colCredit = 17;     // دائن
        $colBalance = 22;    // الرصيد
        // Total: 22+51+34+17+17+17+22 = 180mm

        // Set font for Arabic text (Arial)
        $pdf->SetFont('arial', '', 10);

        // Title
        $pdf->SetFont('arial', 'B', 18);
        $pdf->Cell(0, 10, 'كشف حساب العميل', 0, 1, 'C');
        $pdf->Ln(5);

        // Customer Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'بيانات العميل', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);
        
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
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        
        $pdf->Cell($colBalance, 8, 'الرصيد', 1, 0, 'C', true);
        $pdf->Cell($colCredit, 8, 'دائن', 1, 0, 'C', true);
        $pdf->Cell($colDebit, 8, 'مدين', 1, 0, 'C', true);
        $pdf->Cell($colDays, 8, 'الأيام', 1, 0, 'C', true);
        $pdf->Cell($colDetails, 8, 'الغرف / طريقة الدفع', 1, 0, 'C', true);
        $pdf->Cell($colDescription, 8, 'الوصف', 1, 0, 'C', true);
        $pdf->Cell($colDate, 8, 'التاريخ', 1, 1, 'C', true);

        // Ledger Entries
        $pdf->SetFont('arial', '', 9);
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($ledgerEntries as $entry) {
            $totalDebit += $entry['debit'];
            $totalCredit += $entry['credit'];

            // RTL order: Balance, Credit, Debit, Days, Details, Description, Date
            $balance = number_format($entry['balance'], 0, '.', ',');
            $pdf->Cell($colBalance, 7, $balance, 1, 0, 'C');
            
            $credit = $entry['credit'] > 0 ? number_format($entry['credit'], 0, '.', ',') : '-';
            $pdf->Cell($colCredit, 7, $credit, 1, 0, 'C');
            
            $debit = $entry['debit'] > 0 ? number_format($entry['debit'], 0, '.', ',') : '-';
            $pdf->Cell($colDebit, 7, $debit, 1, 0, 'C');
            
            $days = $entry['days'] ?? '-';
            $pdf->Cell($colDays, 7, $days, 1, 0, 'C');
            
            $details = $entry['type'] === 'reservation' 
                ? ($entry['rooms'] ?? '')
                : ($entry['paymentMethod'] ?? '');
            $pdf->Cell($colDetails, 7, $details, 1, 0, 'C');
            
            $pdf->Cell($colDescription, 7, $entry['description'], 1, 0, 'R');
            
            $pdf->Cell($colDate, 7, $entry['date'], 1, 1, 'C');
        }

        // Totals Row (RTL order)
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $totalColSpan = $colDate + $colDescription + $colDetails + $colDays; // 22+51+34+17 = 124
        $finalBalance = $totalDebit - $totalCredit;
        
        $pdf->Cell($colBalance, 8, number_format($finalBalance, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colCredit, 8, number_format($totalCredit, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colDebit, 8, number_format($totalDebit, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($totalColSpan, 8, 'الإجمالي', 1, 1, 'C', true);

        $pdf->Ln(5);

        // Final Balance
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'الرصيد النهائي: ' . number_format($finalBalance, 0, '.', ','), 0, 1, 'R');

        // Add footer image at the bottom of the page
        $footerImagePath = public_path('footer.png');
        if (file_exists($footerImagePath)) {
            try {
                // Get image dimensions
                $imageInfo = @getimagesize($footerImagePath);
                if ($imageInfo !== false) {
                    // Set maximum width for footer (90% of page width)
                    $maxFooterWidth = $pageWidth * 0.9;
                    
                    // Calculate height maintaining aspect ratio
                    $aspectRatio = $imageInfo[1] / $imageInfo[0];
                    $footerWidthMM = $maxFooterWidth;
                    $footerHeightMM = $footerWidthMM * $aspectRatio;
                    
                    // Get current Y position
                    $currentY = $pdf->GetY();
                    
                    // Check if we need a new page for footer
                    if ($currentY + $footerHeightMM + $bottomMargin > $pageHeight - $bottomMargin) {
                        $pdf->AddPage();
                    }
                    
                    // Temporarily disable RTL for image positioning
                    $pdf->setRTL(false);
                    
                    // Position footer at the very bottom of the page
                    $xPos = ($pageWidth - $footerWidthMM) / 2;
                    $yPos = $pageHeight - $footerHeightMM - $bottomMargin;
                    
                    $pdf->Image($footerImagePath, $xPos, $yPos, $footerWidthMM, $footerHeightMM, 'PNG', '', '', false, 300, '', false, false, 0, false, false, false);
                    
                    // Re-enable RTL
                    $pdf->setRTL(true);
                }
            } catch (\Exception $e) {
                // Silently continue if image fails to load
                Log::warning('Failed to load footer image: ' . $e->getMessage());
            }
        }

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


