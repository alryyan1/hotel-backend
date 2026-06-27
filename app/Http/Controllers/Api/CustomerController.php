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
        $query = Customer::query()
            ->withSum(['transactions as total_debit' => function ($query) {
                $query->where('type', 'debit');
            }], 'amount')
            ->withSum(['transactions as total_credit' => function ($query) {
                $query->where('type', 'credit');
            }], 'amount')
            ->withSum(['transactions as total_refund' => function ($query) {
                $query->where('type', 'refund');
            }], 'amount');

        // Search functionality
        if ($request->has('search') && $request->get('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $customers = $query->orderByDesc('id')->paginate($perPage);

        // Adjust total_debit and total_credit on the collection
        $customers->getCollection()->transform(function ($customer) {
            $refund = $customer->total_refund ?? 0;
            $customer->total_debit = ($customer->total_debit ?? 0) - $refund;
            $customer->total_credit = ($customer->total_credit ?? 0) - $refund;
            return $customer;
        });

        return response()->json($customers);
    }

    public function fetchAll(): JsonResponse
    {
        $customers = Customer::all();
        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'national_id' => 'nullable|string|max:100|unique:customers,national_id',
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female',
                'type' => 'nullable|in:individual,company',
                'contact_name_1' => 'nullable|string|max:255',
                'contact_name_2' => 'nullable|string|max:255',
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
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:50',
                'national_id' => 'nullable|string|max:100|unique:customers,national_id,' . $customer->id,
                'address' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|in:male,female',
                'type' => 'nullable|in:individual,company',
                'contact_name_1' => 'nullable|string|max:255',
                'contact_name_2' => 'nullable|string|max:255',
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

    public function trashed(Request $request): JsonResponse
    {
        $query = Customer::onlyTrashed();

        if ($request->has('search') && $request->get('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('national_id', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $customers = $query->orderByDesc('deleted_at')->paginate($perPage);

        return response()->json($customers);
    }

    public function restore(int $id): JsonResponse
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);
        $customer->restore();
        return response()->json(['message' => 'Restored', 'customer' => $customer]);
    }

    public function forceDestroy(int $id): JsonResponse
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);

        if ($customer->document_path && Storage::disk('public')->exists($customer->document_path)) {
            Storage::disk('public')->delete($customer->document_path);
        }

        $customer->forceDelete();
        return response()->json(['message' => 'Permanently deleted']);
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
        $customer->load('transactions');

        $totalDebit = $customer->transactions()
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredit = $customer->transactions()
            ->where('type', 'credit')
            ->sum('amount');

        $totalRefund = $customer->transactions()
            ->where('type', 'refund')
            ->sum('amount');

        // Refund is an offset of both room charge and payment, net zero on customer ledger.
        $balance = $totalDebit - $totalCredit;

        return response()->json([
            'balance' => $balance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_refund' => $totalRefund,
        ]);
    }

    public function getLedger(Customer $customer): JsonResponse
    {
        // Load customer with transactions and related reservations/rooms/services
        $customer->load(['transactions.reservation.rooms.type', 'transactions.reservationService.service']);

        // Get room types for pricing (for backward compatibility if needed)
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries from transactions
        $ledgerEntries = $this->calculateLedgerFromTransactions($customer->transactions, $roomTypes);

        $totalDebit = array_sum(array_column($ledgerEntries, 'debit'));
        $totalCredit = array_sum(array_column($ledgerEntries, 'credit'));
        $totalRefund = array_sum(array_column($ledgerEntries, 'refund_amount'));

        return response()->json([
            'ledger_entries' => $ledgerEntries,
            'total_debit' => $totalDebit - $totalRefund,
            'total_credit' => $totalCredit - $totalRefund,
            'final_balance' => end($ledgerEntries)['balance'] ?? 0,
        ]);
    }

    public function exportLedgerPdf(Customer $customer): Response
    {
        // Load customer with transactions and related reservations/rooms/services
        $customer->load(['transactions.reservation.rooms.type', 'transactions.reservationService.service']);

        // Get room types for pricing (for backward compatibility if needed)
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries from transactions
        $ledgerEntries = $this->calculateLedgerFromTransactions($customer->transactions, $roomTypes);

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

        $settings = \App\Models\HotelSetting::first();

        if ($settings && $settings->header_path) {
            $headerImagePath = storage_path('app/public/' . $settings->header_path);
            if (file_exists($headerImagePath)) {
                try {
                    $imageInfo = @getimagesize($headerImagePath);
                    if ($imageInfo !== false) {
                        $maxHeaderWidth = $pageWidth;
                        $aspectRatio = $imageInfo[1] / $imageInfo[0];
                        $headerWidthMM = $maxHeaderWidth;
                        $headerHeightMM = $headerWidthMM * $aspectRatio;
                        
                        $pdf->setRTL(false);
                        $pdf->Image($headerImagePath, 0, 0, $headerWidthMM, $headerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                        
                        $pdf->SetY($headerHeightMM + 5);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load header image: ' . $e->getMessage());
                }
            }
        } elseif ($settings && $settings->logo_path) {
            // Fallback to logo if no header is present
            $logoImagePath = storage_path('app/public/' . $settings->logo_path);
            if (file_exists($logoImagePath)) {
                try {
                    $imageInfo = @getimagesize($logoImagePath);
                    if ($imageInfo !== false) {
                        $maxLogoWidth = $pageWidth * 0.15;
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
        }

        $pdf->setRTL(true);
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
            ($customer->type === 'company' && $customer->contact_name_1) ? 'العميل 1: ' . $customer->contact_name_1 : null,
            ($customer->type === 'company' && $customer->contact_name_2) ? 'العميل 2: ' . $customer->contact_name_2 : null,
            $customer->phone ? 'الهاتف: ' . $customer->phone : null,
            $customer->national_id ? ($customer->type === 'company' ? 'السجل التجاري: ' : 'الرقم الوطني: ') . $customer->national_id : null,
        ];

        foreach (array_filter($customerInfo) as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Ledger Table Header
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        // ... (code before header)

        // Ledger Table Header
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        // NEW ORDER (RTL: Date -> Description -> Details -> Days -> Debit -> Credit -> Balance)
        $pdf->Cell($colDate, 8, 'التاريخ', 1, 0, 'C', true);         // 1. Far Right
        $pdf->Cell($colDescription, 8, 'الوصف', 1, 0, 'C', true);     // 2.
        $pdf->Cell($colDetails, 8, 'الغرف / طريقة الدفع', 1, 0, 'C', true); // 3.
        $pdf->Cell($colDays, 8, 'الأيام', 1, 0, 'C', true);         // 4.
        $pdf->Cell($colDebit, 8, 'مدين', 1, 0, 'C', true);          // 5.
        $pdf->Cell($colCredit, 8, 'دائن', 1, 0, 'C', true);         // 6.
        $pdf->Cell($colBalance, 8, 'الرصيد', 1, 1, 'C', true);      // 7. Far Left (Note: 1, 1 moves to the next line)

        // ... (code after header)
        // Ledger Entries
        $pdf->SetFont('arial', '', 9);
        $totalDebit = 0;
        $totalCredit = 0;
        $totalRefund = 0;

        foreach ($ledgerEntries as $entry) {
            $totalDebit += $entry['debit'];
            $totalCredit += $entry['credit'];
            if (isset($entry['refund_amount'])) {
                $totalRefund += $entry['refund_amount'];
            }

            // RTL order: Balance, Credit, Debit, Days, Details, Description, Date
            // ... (inside foreach ($ledgerEntries as $entry) { ... )

            // Calculate required lines for description
            $descLines = $pdf->getNumLines($entry['description'], $colDescription);
            $rowHeight = max(8, $descLines * 5 + 2); // Minimum 8mm, expand as needed

            // Check if we need a new page
            if ($pdf->GetY() + $rowHeight > $pageHeight - $bottomMargin) {
                $pdf->AddPage();
            }

            // Draw cells with MultiCell to allow natural wrapping and vertical centering
            // RTL order: Date, Description, Details, Days, Debit, Credit, Balance
            
            $pdf->MultiCell($colDate, $rowHeight, $entry['date'], 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
            
            $pdf->MultiCell($colDescription, $rowHeight, $entry['description'], 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

            if ($entry['type'] === 'reservation') {
                $details = $entry['rooms'] ?? '';
            } elseif ($entry['type'] === 'service') {
                $details = 'خدمة';
            } else {
                $details = $entry['paymentMethod'] ?? '';
            }
            $pdf->MultiCell($colDetails, $rowHeight, $details, 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

            $days = $entry['days'] ?? '-';
            $pdf->MultiCell($colDays, $rowHeight, $days, 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

            $debit = $entry['debit'] > 0 ? number_format($entry['debit'], 0, '.', ',') : '-';
            $pdf->MultiCell($colDebit, $rowHeight, $debit, 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

            $credit = $entry['credit'] > 0 ? number_format($entry['credit'], 0, '.', ',') : '-';
            $pdf->MultiCell($colCredit, $rowHeight, $credit, 1, 'C', false, 0, '', '', true, 0, false, true, $rowHeight, 'M');

            $balance = number_format($entry['balance'], 0, '.', ',');
            $pdf->MultiCell($colBalance, $rowHeight, $balance, 1, 'C', false, 1, '', '', true, 0, false, true, $rowHeight, 'M');

            // ... (end of foreach loop)
        }

        // Totals Row (RTL order)
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $totalColSpan = $colDate + $colDescription + $colDetails + $colDays; // 22+51+34+17 = 124
        
        // Deduct refunds from totals to show net paid amount
        $netDebit = $totalDebit - $totalRefund;
        $netCredit = $totalCredit - $totalRefund;
        $finalBalance = $netDebit - $netCredit;

        $pdf->Cell($totalColSpan, 8, 'الإجمالي', 1, 0, 'C', true);
        $pdf->Cell($colDebit, 8, number_format($netDebit, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colCredit, 8, number_format($netCredit, 0, '.', ','), 1, 0, 'C', true);
        $pdf->Cell($colBalance, 8, number_format($finalBalance, 0, '.', ','), 1, 1, 'C', true);

        $pdf->Ln(5);

        // Final Balance
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'الرصيد النهائي: ' . number_format($finalBalance, 0, '.', ','), 0, 1, 'R');

        $pdf->SetAutoPageBreak(false);

        // Pre-calculate footer height so stamps can be anchored just above it
        $footerHeightMM  = 0;
        $footerImagePath = null;
        if ($settings && $settings->footer_path) {
            $fPath = storage_path('app/public/' . $settings->footer_path);
            if (file_exists($fPath)) {
                $fInfo = @getimagesize($fPath);
                if ($fInfo) {
                    $footerHeightMM  = $pageWidth * ($fInfo[1] / $fInfo[0]);
                    $footerImagePath = $fPath;
                }
            }
        }

        $stampWidthMM = 40;
        $stampXPos    = $leftMargin;
        $eStampXPos   = $leftMargin + $stampWidthMM + 5;

        if ($settings && $settings->stamp_path) {
            $sPath = storage_path('app/public/' . $settings->stamp_path);
            if (file_exists($sPath)) {
                try {
                    $imgInfo = @getimagesize($sPath);
                    if ($imgInfo) {
                        $stampHeightMM = $stampWidthMM * ($imgInfo[1] / $imgInfo[0]);
                        $yPos = $pageHeight - $footerHeightMM - $stampHeightMM - 5;
                        $pdf->setRTL(false);
                        $pdf->Image($sPath, $stampXPos, $yPos, $stampWidthMM, $stampHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load stamp image: ' . $e->getMessage());
                }
            }
        }

        if ($settings && $settings->e_stamp_path) {
            $ePath = storage_path('app/public/' . $settings->e_stamp_path);
            if (file_exists($ePath)) {
                try {
                    $imgInfo = @getimagesize($ePath);
                    if ($imgInfo) {
                        $eStampHeightMM = $stampWidthMM * ($imgInfo[1] / $imgInfo[0]);
                        $yPos = $pageHeight - $footerHeightMM - $eStampHeightMM - 5;
                        $pdf->setRTL(false);
                        $pdf->Image($ePath, $eStampXPos, $yPos, $stampWidthMM, $eStampHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load e-stamp image: ' . $e->getMessage());
                }
            }
        }

        if ($footerImagePath) {
            try {
                $pdf->setRTL(false);
                $pdf->Image($footerImagePath, 0, $pageHeight - $footerHeightMM - 4, $pageWidth, $footerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                $pdf->setRTL(true);
            } catch (\Exception $e) {
                Log::warning('Failed to load footer image: ' . $e->getMessage());
            }
        }

        $pdf->SetAutoPageBreak(true, $bottomMargin);

        // Generate PDF and return as response
        $filename = 'customer_ledger_' . $customer->id . '_' . date('Y-m-d') . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    private function calculateLedgerFromTransactions($transactions, $roomTypes): array
    {
        $entries = [];
        $runningBalance = 0;

        // Sort transactions by ID (insertion order)
        $sortedTransactions = $transactions->sortBy('id');

        $methodLabels = [
            'cash' => 'نقدي',
            'bankak' => 'بنكك',
            'Ocash' => 'أوكاش',
            'fawri' => 'فوري'
        ];

        foreach ($sortedTransactions as $transaction) {
            if ($transaction->type === 'debit') {
                // Service debit transaction
                if ($transaction->reservation_service_id) {
                    $serviceName = $transaction->reservationService?->service?->name ?? ($transaction->notes ?? 'خدمة');
                    $runningBalance += $transaction->amount;
                    $entries[] = [
                        'id'                     => $transaction->id,
                        'reservation_service_id' => $transaction->reservation_service_id,
                        'reservation_id'         => $transaction->reservation_id,
                        'type'                   => 'service',
                        'date'                   => date('d/m/Y', strtotime($transaction->transaction_date)),
                        'description'            => 'خدمة: ' . $serviceName,
                        'days'                   => null,
                        'debit'                  => $transaction->amount,
                        'credit'                 => 0,
                        'balance'                => $runningBalance,
                    ];
                    continue;
                }

                // Room debit transaction (reservation)
                $reservation = $transaction->reservation;

                if ($reservation) {
                    $checkIn = new \DateTime($reservation->check_in_date);
                    $checkOut = new \DateTime($reservation->check_out_date);
                    $days = max(1, $checkIn->diff($checkOut)->days);

                    // Per-room transaction: use notes for room name; legacy: show all rooms
                    $isPerRoom = str_contains($transaction->reference ?? '', '-ROOM-');
                    if ($isPerRoom && $transaction->notes) {
                        $roomDisplay = $transaction->notes;
                    } else {
                        $roomNames = $reservation->rooms->map(fn($r) => 'غرفة ' . $r->number)->all();
                        $roomDisplay = implode(', ', $roomNames);
                    }

                    $runningBalance += $transaction->amount;

                    $entries[] = [
                        'id'             => $transaction->id,
                        'reservation_id' => $reservation->id,
                        'type'           => 'reservation',
                        'date'           => date('d/m/Y', strtotime($transaction->transaction_date)),
                        'description'    => 'حجز #' . $reservation->id . ' - ' . $roomDisplay,
                        'rooms'          => $roomDisplay,
                        'days'           => $days,
                        'debit'          => $transaction->amount,
                        'credit'         => 0,
                        'balance'        => $runningBalance
                    ];
                } else {
                    // Debit without reservation (shouldn't happen normally, but handle gracefully)
                    $runningBalance += $transaction->amount;
                    $entries[] = [
                        'id' => $transaction->id,
                        'type' => 'reservation',
                        'date' => date('d/m/Y', strtotime($transaction->transaction_date)),
                        'description' => $transaction->notes ?? 'حجز',
                        'rooms' => '-',
                        'days' => null,
                        'debit' => $transaction->amount,
                        'credit' => 0,
                        'balance' => $runningBalance
                    ];
                }
            } elseif ($transaction->type === 'refund') {
                // Refund transaction (early checkout)
                // As per user request, refund shouldn't be counted as credit or affect balance directly in the ledger table
                
                $notes = $transaction->notes ?? ('استرجاع مبلغ - ' . ($transaction->reference ?? ''));
                $description = $notes . ' (المبلغ: ' . number_format($transaction->amount, 0, '.', ',') . ')';

                $entries[] = [
                    'id' => $transaction->id,
                    'reservation_id' => $transaction->reservation_id,
                    'type' => 'refund',
                    'date' => date('d/m/Y', strtotime($transaction->transaction_date)),
                    'description' => $description,
                    'days' => null,
                    'debit' => 0,
                    'credit' => 0,
                    'refund_amount' => $transaction->amount,
                    'balance' => $runningBalance
                ];
            } else {
                // Credit transaction (payment)
                $runningBalance -= $transaction->amount;

                $entries[] = [
                    'id' => $transaction->id,
                    'type' => 'payment',
                    'date' => date('d/m/Y', strtotime($transaction->transaction_date)),
                    'description' => 'دفعة - ' . ($transaction->reference ?? ''),
                    'paymentMethod' => $methodLabels[$transaction->method] ?? $transaction->method ?? '',
                    'days' => null,
                    'debit' => 0,
                    'credit' => $transaction->amount,
                    'balance' => $runningBalance
                ];
            }
        }

        return $entries;
    }
}
