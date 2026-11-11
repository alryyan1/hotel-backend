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

        $balance = $totalDebit - $totalCredit;

        return response()->json([
            'balance' => $balance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
        ]);
    }

    public function getLedger(Customer $customer): JsonResponse
    {
        // Load customer with transactions and related reservations/rooms/services
        $customer->load(['transactions.reservation.rooms.type', 'transactions.reservationService.service', 'transactions.user:id,name']);

        // Get room types for pricing (for backward compatibility if needed)
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries from transactions
        $ledgerEntries = $this->calculateLedgerFromTransactions($customer->transactions, $roomTypes);

        // Totals and the final balance are kept per currency — SDG and USD activity
        // never get summed together, since that total wouldn't mean anything.
        $totalsByCurrency = [];
        foreach ($ledgerEntries as $entry) {
            $currency = $entry['currency'] ?? 'SDG';
            $totalsByCurrency[$currency] ??= ['total_debit' => 0, 'total_credit' => 0, 'final_balance' => 0];
            $totalsByCurrency[$currency]['total_debit'] += $entry['debit'];
            $totalsByCurrency[$currency]['total_credit'] += $entry['credit'];
            $totalsByCurrency[$currency]['final_balance'] = $entry['balance'];
        }

        // Totals/final balance above are computed in chronological (ascending) order,
        // which the running balance depends on — only the displayed list is reversed,
        // so the newest reservation/transaction shows first.
        return response()->json([
            'ledger_entries' => array_reverse($ledgerEntries),
            'totals_by_currency' => $totalsByCurrency,
            'early_checkouts' => $this->earlyCheckoutsFor($customer),
        ]);
    }

    /**
     * Reservations that checked out early: only a hint to refund the unused nights —
     * nothing is posted to the ledger and no refund is recorded.
     */
    private function earlyCheckoutHint(array $ec): string
    {
        $nights = $ec['nights'] ? ' (' . $ec['nights'] . ' ليلة)' : '';
        $amount = number_format($ec['amount'], $ec['currency'] === 'USD' ? 2 : 0, '.', ',');

        return 'يوجد مغادرة مبكرة للحجز #' . $ec['reservation_id'] . $nights . ' — يرجى استرجاع مبلغ قدره ' . $amount . ' ' . $ec['currency'];
    }

    private function earlyCheckoutsFor(Customer $customer): array
    {
        return $customer->reservations()
            ->where('early_checkout_refund', '>', 0)
            ->orderByDesc('id')
            ->get(['id', 'currency', 'early_checkout_nights', 'early_checkout_refund'])
            ->map(fn ($r) => [
                'reservation_id' => $r->id,
                'currency'       => $r->currency ?? 'SDG',
                'nights'         => $r->early_checkout_nights,
                'amount'         => (float) $r->early_checkout_refund,
            ])
            ->all();
    }

    public function exportLedgerPdf(Customer $customer): Response
    {
        // Load customer with transactions and related reservations/rooms/services
        $customer->load(['transactions.reservation.rooms.type', 'transactions.reservationService.service', 'transactions.user:id,name']);

        // Get room types for pricing (for backward compatibility if needed)
        $roomTypes = RoomType::all()->keyBy('id');

        // Calculate ledger entries from transactions
        $ledgerEntries = $this->calculateLedgerFromTransactions($customer->transactions, $roomTypes);

        // Create PDF with RTL support (landscape)
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
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

        // Page dimensions (A4 landscape)
        $pageWidth = 297; // A4 landscape width in mm
        $pageHeight = 210; // A4 landscape height in mm

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
        // Calculate available width (A4 landscape width = 297mm, minus margins)
        $availableWidth = $pageWidth - $leftMargin - $rightMargin; // 267mm

        // Column widths, RTL order (first is far right); total = 267mm
        $colWidths = [
            'date'        => 30,
            'description' => 90,
            'currency'    => 18,
            'days'        => 16,
            'rate'        => 26,
            'debit'       => 28,
            'credit'      => 28,
            'balance'     => 31,
        ];

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

        // Payments tied to a reservation are rolled into that reservation's
        // المدفوع/المتبقي columns below, exactly like the on-screen ledger — so they're
        // left out of the printed rows here instead of appearing a second time as
        // their own line. Only standalone (legacy, reservation-less) payments print
        // as their own row. Order matches the on-screen ledger too: newest first.
        $paidByReservation = [];
        foreach ($ledgerEntries as $entry) {
            if ($entry['type'] === 'payment' && !empty($entry['reservation_id'])) {
                $paidByReservation[$entry['reservation_id']] = ($paidByReservation[$entry['reservation_id']] ?? 0) + $entry['credit'];
            }
        }

        // Extensions and transfers aren't rows of the main table (same as the web
        // ledger): they're listed in their own details table after the totals, and
        // their difference is added to the "remaining" of the reservation's first
        // booking row.
        $isAdjustment = fn ($entry) => $entry['type'] === 'reservation' && !empty($entry['operation_type']);
        $adjustments = array_values(array_filter($ledgerEntries, $isAdjustment));

        $parentRowByReservation = [];
        $adjustmentsByReservation = [];
        foreach ($ledgerEntries as $entry) {
            if ($entry['type'] !== 'reservation' || empty($entry['reservation_id'])) {
                continue;
            }
            if ($isAdjustment($entry)) {
                $adjustmentsByReservation[$entry['reservation_id']] = ($adjustmentsByReservation[$entry['reservation_id']] ?? 0) + $entry['debit'];
            } elseif (!isset($parentRowByReservation[$entry['reservation_id']])) {
                $parentRowByReservation[$entry['reservation_id']] = $entry['id'];
            }
        }

        $printEntries = array_reverse(array_values(array_filter(
            $ledgerEntries,
            fn ($entry) => !($entry['type'] === 'payment' && !empty($entry['reservation_id'])) && !$isAdjustment($entry)
        )));

        // Ledger Entries
        $pdf->SetFont('arial', '', 9);
        // SDG and USD rows are never summed together — each currency keeps its own
        // running totals, same as the on-screen ledger. Computed from the full,
        // chronologically-ordered entry list (not the filtered/reversed $printEntries)
        // so the running balance and totals stay correct regardless of print order.
        $totalsByCurrency = [];
        foreach ($ledgerEntries as $entry) {
            $entryCurrency = $entry['currency'] ?? 'SDG';
            $totalsByCurrency[$entryCurrency] ??= ['debit' => 0, 'credit' => 0, 'balance' => 0];
            $totalsByCurrency[$entryCurrency]['debit'] += $entry['debit'];
            $totalsByCurrency[$entryCurrency]['credit'] += $entry['credit'];
            $totalsByCurrency[$entryCurrency]['balance'] = $entry['balance'];
        }

        $fieldLabels = [
            'date'        => 'التاريخ',
            'description' => 'الوصف',
            'currency'    => 'العملة',
            'days'        => 'الأيام',
            'rate'        => 'سعر الليلة',
            'debit'       => 'الإجمالي',
            'credit'      => 'المدفوع',
            'balance'     => 'المتبقي',
        ];

        // Each entry's printed values, keyed like $fieldLabels
        $printRows = [];
        foreach ($printEntries as $entry) {
            $isReservationCharge = in_array($entry['type'], ['reservation', 'service'], true) && !empty($entry['reservation_id']);
            $paidForThisReservation = $isReservationCharge ? ($paidByReservation[$entry['reservation_id']] ?? 0) : 0;

            // For room bookings, append the segment's exact period (from -> to) so an
            // extension is clearly distinguished from the original booking segment on
            // the printed statement. The nightly rate gets its own row below.
            $printedDescription = $entry['description'];
            if ($entry['type'] === 'reservation' && !empty($entry['period'])) {
                $printedDescription .= "\n" . $entry['period'];
            }
            // No separate rooms/method column — the room is already in a booking's
            // description, so only a payment's method needs adding here.
            if ($entry['type'] === 'payment' && !empty($entry['paymentMethod'])) {
                $printedDescription .= ' (' . $entry['paymentMethod'] . ')';
            }

            // المدفوع/المتبقي for a charge reflect what's actually been paid
            // toward its whole reservation (same figures as the "المدفوعات" total
            // on the web ledger), not this entry's own (always-zero) credit.
            if ($isReservationCharge) {
                $rowDebit = $entry['debit'];
                if (($parentRowByReservation[$entry['reservation_id']] ?? null) === $entry['id']) {
                    $rowDebit += $adjustmentsByReservation[$entry['reservation_id']] ?? 0;
                }
                $credit = $paidForThisReservation > 0 ? number_format($paidForThisReservation, 0, '.', ',') : '-';
                $balance = number_format($rowDebit - $paidForThisReservation, 0, '.', ',');
            } elseif ($entry['type'] === 'payment') {
                $credit = $entry['credit'] > 0 ? number_format($entry['credit'], 0, '.', ',') : '-';
                $balance = '-';
            } else {
                $credit = $entry['credit'] > 0 ? number_format($entry['credit'], 0, '.', ',') : '-';
                $balance = number_format($entry['balance'], 0, '.', ',');
            }

            $printRows[] = [
                'date'        => $entry['date'],
                'description' => $printedDescription,
                'currency'    => $entry['currency'] ?? 'SDG',
                'days'        => (string) ($entry['days'] ?? '-'),
                'rate'        => !empty($entry['rate']) ? number_format($entry['rate'], 0, '.', ',') : '-',
                'debit'       => (float) $entry['debit'] != 0 ? number_format($entry['debit'], 0, '.', ',') : '-',
                'credit'      => $credit,
                'balance'     => $balance,
            ];
        }

        // Header row; repeated at the top of every page the table runs onto
        $drawHeaderRow = function () use ($pdf, $fieldLabels, $colWidths) {
            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(230, 230, 230);
            foreach ($fieldLabels as $key => $label) {
                $pdf->Cell($colWidths[$key], 8, $label, 1, $key === array_key_last($fieldLabels) ? 1 : 0, 'C', true);
            }
            $pdf->SetFont('arial', '', 9);
        };

        $drawHeaderRow();

        foreach ($printRows as $row) {
            // Row grows to fit the tallest wrapped value (usually the description)
            $lines = 1;
            foreach ($fieldLabels as $key => $label) {
                $lines = max($lines, $pdf->getNumLines($row[$key], $colWidths[$key]));
            }
            $rowHeight = max(8, $lines * 5 + 2);

            if ($pdf->GetY() + $rowHeight > $pageHeight - $bottomMargin) {
                $pdf->AddPage();
                $drawHeaderRow();
            }

            foreach ($fieldLabels as $key => $label) {
                $isLast = $key === array_key_last($fieldLabels);
                $pdf->MultiCell($colWidths[$key], $rowHeight, $row[$key], 1, 'C', false, $isLast ? 1 : 0, '', '', true, 0, false, true, $rowHeight, 'M');
            }
        }

        // Totals — one row per currency (RTL order), never summed together
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        // spans everything before the money columns
        $totalColSpan = $availableWidth - $colWidths['debit'] - $colWidths['credit'] - $colWidths['balance'];

        foreach ($totalsByCurrency as $currency => $totals) {
            $pdf->Cell($totalColSpan, 8, 'الإجمالي (' . $currency . ')', 1, 0, 'C', true);
            $pdf->Cell($colWidths['debit'], 8, number_format($totals['debit'], 0, '.', ','), 1, 0, 'C', true);
            $pdf->Cell($colWidths['credit'], 8, number_format($totals['credit'], 0, '.', ','), 1, 0, 'C', true);
            $pdf->Cell($colWidths['balance'], 8, number_format($totals['balance'], 0, '.', ','), 1, 1, 'C', true);
        }

        // Early checkout — a hint only, not part of the totals above
        $earlyCheckouts = $this->earlyCheckoutsFor($customer);
        if ($earlyCheckouts) {
            $pdf->Ln(2);
            $pdf->SetFont('arial', '', 10);
            foreach ($earlyCheckouts as $ec) {
                $pdf->Cell(0, 7, '* ' . $this->earlyCheckoutHint($ec), 0, 1, 'R');
            }
        }

        $pdf->Ln(5);

        // Final Balance — one line per currency
        $pdf->SetFont('arial', 'B', 12);
        foreach ($totalsByCurrency as $currency => $totals) {
            $pdf->Cell(0, 8, 'الرصيد النهائي (' . $currency . '): ' . number_format($totals['balance'], 0, '.', ','), 0, 1, 'R');
        }

        // Services balance — still owed on unpaid requests at their quoted price, per currency
        $services = \App\Models\ReservationService::with('service')
            ->whereHas('reservation', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderByDesc('id')
            ->get();

        $svcTotals = [];
        foreach ($services as $s) {
            $currency = ($s->paid_at ? $s->currency : $s->price_currency) ?? 'SDG';
            $svcTotals[$currency] ??= ['paid' => 0, 'unpaid' => 0];
            if ($s->paid_at) {
                $svcTotals[$currency]['paid'] += $s->billed_amount;
            } elseif ($s->price !== null) {
                $svcTotals[$currency]['unpaid'] += $s->price * ($s->quantity ?? 1);
            }
        }

        foreach ($svcTotals as $currency => $totals) {
            $pdf->Cell(0, 8, 'رصيد الخدمات (' . $currency . '): ' . number_format($totals['unpaid'], $currency === 'USD' ? 2 : 0, '.', ','), 0, 1, 'R');
        }

        // Extension/transfer details — same columns as the web ledger's table
        if ($adjustments) {
            $adjLabels = [
                'date'      => 'التاريخ',
                'reservation' => 'رقم الحجز',
                'operation' => 'العملية',
                'details'   => 'التفاصيل',
                'period'    => 'الفترة',
                'currency'  => 'العملة',
                'diff'      => 'الفرق في المبلغ',
            ];
            $adjWidths = [
                'date'        => 26,
                'reservation' => 22,
                'operation'   => 20,
                'details'     => 92,
                'period'      => 50,
                'currency'    => 18,
                'diff'        => 39,
            ]; // total = 267mm

            $drawAdjHeader = function () use ($pdf, $adjLabels, $adjWidths) {
                $pdf->SetFont('arial', 'B', 10);
                $pdf->SetFillColor(230, 230, 230);
                foreach ($adjLabels as $key => $label) {
                    $pdf->Cell($adjWidths[$key], 8, $label, 1, $key === array_key_last($adjLabels) ? 1 : 0, 'C', true);
                }
                $pdf->SetFont('arial', '', 9);
            };

            $pdf->Ln(5);
            if ($pdf->GetY() + 26 > $pageHeight - $bottomMargin) {
                $pdf->AddPage();
            }
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'تفاصيل التمديد والتحويل', 0, 1, 'R');
            $drawAdjHeader();

            foreach ($adjustments as $a) {
                $rate = !empty($a['rate']) ? number_format($a['rate'], 0, '.', ',') : '-';
                $days = $a['days'] ?? '-';
                if ($a['operation_type'] === 'extension') {
                    $details = ($a['rooms'] ?? '') . ' — ' . $days . ' ليلة × ' . $rate;
                } else {
                    // Drop the trailing "N ليلة (فرق ...)" from the transfer note, as the web ledger does
                    $note = isset($a['notes'])
                        ? preg_replace('/:?\s*\d+\s*ليلة\s*\(فرق [^)]*\)\s*$/u', '', $a['notes'])
                        : $a['description'];
                    $details = $note . ' — سعر الغرفة الجديدة: ' . $rate . ' × ' . $days . ' ليلة';
                }
                $diff = (float) $a['debit'];

                $row = [
                    'date'        => $a['date'],
                    'reservation' => !empty($a['reservation_id']) ? '#' . $a['reservation_id'] : '-',
                    'operation'   => $a['operation_type'] === 'extension' ? 'تمديد' : 'تحويل',
                    'details'     => $details,
                    'period'      => $a['period'] ?? '-',
                    'currency'    => $a['currency'] ?? 'SDG',
                    'diff'        => ($diff > 0 ? '+' : '') . number_format($diff, 0, '.', ','),
                ];

                $lines = 1;
                foreach ($adjLabels as $key => $label) {
                    $lines = max($lines, $pdf->getNumLines($row[$key], $adjWidths[$key]));
                }
                $rowHeight = max(8, $lines * 5 + 2);

                if ($pdf->GetY() + $rowHeight > $pageHeight - $bottomMargin) {
                    $pdf->AddPage();
                    $drawAdjHeader();
                }

                foreach ($adjLabels as $key => $label) {
                    $isLast = $key === array_key_last($adjLabels);
                    $pdf->MultiCell($adjWidths[$key], $rowHeight, $row[$key], 1, 'C', false, $isLast ? 1 : 0, '', '', true, 0, false, true, $rowHeight, 'M');
                }
            }
        }

        // Services — tracked apart from the ledger, so they get their own table and
        // totals (same as the web ledger). Printed only when the customer has any.
        // $services / $svcTotals are loaded above with the services balance.
        if ($services->isNotEmpty()) {
            $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري'];
            $svcLabels = [
                'date'        => 'التاريخ',
                'reservation' => 'رقم الحجز',
                'service'     => 'الخدمة',
                'quantity'    => 'العدد',
                'price'       => 'السعر',
                'paid'        => 'المبلغ المدفوع',
                'method'      => 'طريقة الدفع',
                'status'      => 'الحالة',
                'notes'       => 'ملاحظات',
            ];
            $svcWidths = [
                'date'        => 34,
                'reservation' => 20,
                'service'     => 50,
                'quantity'    => 14,
                'price'       => 30,
                'paid'        => 32,
                'method'      => 22,
                'status'      => 22,
                'notes'       => 43,
            ]; // total = 267mm

            $drawSvcHeader = function () use ($pdf, $svcLabels, $svcWidths) {
                $pdf->SetFont('arial', 'B', 10);
                $pdf->SetFillColor(230, 230, 230);
                foreach ($svcLabels as $key => $label) {
                    $pdf->Cell($svcWidths[$key], 8, $label, 1, $key === array_key_last($svcLabels) ? 1 : 0, 'C', true);
                }
                $pdf->SetFont('arial', '', 9);
            };

            $pdf->Ln(5);
            if ($pdf->GetY() + 26 > $pageHeight - $bottomMargin) {
                $pdf->AddPage();
            }
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 8, 'الخدمات', 0, 1, 'R');
            $drawSvcHeader();

            foreach ($services as $s) {
                $decimalsFor = fn ($c) => $c === 'USD' ? 2 : 0;
                $row = [
                    'date'        => $s->created_at->format('d/m/Y h:i') . ' ' . ($s->created_at->hour < 12 ? 'ص' : 'م'),
                    'reservation' => '#' . $s->reservation_id,
                    'service'     => $s->service->name ?? '-',
                    'quantity'    => (string) ($s->quantity ?? 1),
                    'price'       => $s->price !== null ? number_format($s->price, $decimalsFor($s->price_currency), '.', ',') . ' ' . $s->price_currency : '-',
                    'paid'        => $s->paid_at ? number_format($s->billed_amount, $decimalsFor($s->currency), '.', ',') . ' ' . $s->currency : '-',
                    'method'      => $s->paid_at ? ($methodLabels[$s->payment_method] ?? ($s->payment_method ?? '-')) : '-',
                    'status'      => $s->paid_at ? 'مسددة' : 'غير مسددة',
                    'notes'       => $s->notes ?: '-',
                ];

                $lines = 1;
                foreach ($svcLabels as $key => $label) {
                    $lines = max($lines, $pdf->getNumLines($row[$key], $svcWidths[$key]));
                }
                $rowHeight = max(8, $lines * 5 + 2);

                if ($pdf->GetY() + $rowHeight > $pageHeight - $bottomMargin) {
                    $pdf->AddPage();
                    $drawSvcHeader();
                }

                foreach ($svcLabels as $key => $label) {
                    $isLast = $key === array_key_last($svcLabels);
                    $pdf->MultiCell($svcWidths[$key], $rowHeight, $row[$key], 1, 'C', false, $isLast ? 1 : 0, '', '', true, 0, false, true, $rowHeight, 'M');
                }
            }

            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(240, 240, 240);
            $svcLabelSpan = $svcWidths['date'] + $svcWidths['reservation'] + $svcWidths['service'] + $svcWidths['quantity'] + $svcWidths['price'];
            $svcRestSpan  = $svcWidths['method'] + $svcWidths['status'] + $svcWidths['notes'];
            foreach ($svcTotals as $currency => $totals) {
                $pdf->Cell($svcLabelSpan, 8, 'إجمالي الخدمات (' . $currency . ')', 1, 0, 'C', true);
                $pdf->Cell($svcWidths['paid'], 8, number_format($totals['paid'], $currency === 'USD' ? 2 : 0, '.', ','), 1, 0, 'C', true);
                $pdf->Cell($svcRestSpan, 8, $totals['unpaid'] > 0 ? 'غير مسدد: ' . number_format($totals['unpaid'], $currency === 'USD' ? 2 : 0, '.', ',') : '-', 1, 1, 'C', true);
            }
        }

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
        // Keyed by currency: SDG and USD transactions never share a running total,
        // since summing amounts across currencies produces a meaningless number.
        $runningBalance = [];

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
                    $currency = $transaction->currency ?? 'SDG';
                    $runningBalance[$currency] = ($runningBalance[$currency] ?? 0) + $transaction->amount;
                    $entries[] = [
                        'id'                     => $transaction->id,
                        'reservation_service_id' => $transaction->reservation_service_id,
                        'reservation_id'         => $transaction->reservation_id,
                        'type'                   => 'service',
                        'date'                   => date('d/m/Y', strtotime($transaction->transaction_date)),
                        'description'            => 'خدمة: ' . $serviceName,
                        'currency'               => $currency,
                        'days'                   => null,
                        'debit'                  => $transaction->amount,
                        'credit'                 => 0,
                        'balance'                => $runningBalance[$currency],
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

                    // A transfer's full text belongs in the description; this column only names the room
                    $roomsColumn = $transaction->isTransferDifference() && $transaction->room
                        ? 'غرفة ' . $transaction->room->number
                        : $roomDisplay;

                    // Prefer this transaction's own frozen segment (nights/rate/dates,
                    // recorded when it was charged) over the reservation's current full
                    // date range — so an extension priced differently than the original
                    // booking shows its own days/dates/rate instead of a blended total.
                    $isExtension = $transaction->operation_type === 'extension'
                        || str_starts_with($transaction->reference ?? '', 'EXT-');
                    $operationType = $isExtension ? 'extension' : ($transaction->isTransferDifference() ? 'transfer' : null);
                    $segmentNights = $transaction->nights ?? $days;
                    $segmentCheckIn = $transaction->check_in_date
                        ? date('d/m/Y', strtotime($transaction->check_in_date))
                        : date('d/m/Y', strtotime($reservation->check_in_date));
                    $segmentCheckOut = $transaction->check_out_date
                        ? date('d/m/Y', strtotime($transaction->check_out_date))
                        : date('d/m/Y', strtotime($reservation->check_out_date));

                    $currency = $transaction->currency ?? 'SDG';
                    $runningBalance[$currency] = ($runningBalance[$currency] ?? 0) + $transaction->amount;

                    $entries[] = [
                        'id'             => $transaction->id,
                        'reservation_id' => $reservation->id,
                        'type'           => 'reservation',
                        // When the booking was made, not the arrival date; an extension/transfer
                        // row shows when that charge itself was added.
                        'date'           => date('d/m/Y', strtotime(
                            ($operationType ? $transaction->created_at : $reservation->created_at)
                                ?? $transaction->transaction_date
                        )),
                        'description'    => 'حجز #' . $reservation->id . ' - ' . $roomDisplay . ($isExtension ? ' (تمديد)' : ''),
                        'rooms'          => $roomsColumn,
                        'currency'       => $currency,
                        'days'           => $segmentNights,
                        'period'         => $segmentCheckIn . ' → ' . $segmentCheckOut,
                        'rate'           => $transaction->rate !== null ? (float) $transaction->rate : null,
                        'is_extension'   => $isExtension,
                        'operation_type' => $operationType,
                        'notes'          => $transaction->notes,
                        'debit'          => $transaction->amount,
                        'credit'         => 0,
                        'balance'        => $runningBalance[$currency]
                    ];
                } else {
                    // Debit without reservation (shouldn't happen normally, but handle gracefully)
                    $currency = $transaction->currency ?? 'SDG';
                    $runningBalance[$currency] = ($runningBalance[$currency] ?? 0) + $transaction->amount;
                    $entries[] = [
                        'id' => $transaction->id,
                        'type' => 'reservation',
                        'date' => date('d/m/Y', strtotime($transaction->transaction_date)),
                        'description' => $transaction->notes ?? 'حجز',
                        'rooms' => '-',
                        'currency' => $currency,
                        'days' => null,
                        'debit' => $transaction->amount,
                        'credit' => 0,
                        'balance' => $runningBalance[$currency]
                    ];
                }
            } else {
                // Credit transaction (payment)
                $currency = $transaction->currency ?? 'SDG';
                $runningBalance[$currency] = ($runningBalance[$currency] ?? 0) - $transaction->amount;

                $entries[] = [
                    'id' => $transaction->id,
                    'reservation_id' => $transaction->reservation_id,
                    'type' => 'payment',
                    'date' => date('d/m/Y', strtotime($transaction->transaction_date)),
                    'description' => 'دفعة - ' . ($transaction->reference ?? ''),
                    'paymentMethod' => $methodLabels[$transaction->method] ?? $transaction->method ?? '',
                    // Who recorded the payment, and its shift — the UI uses both to offer cancelling it
                    'paid_by' => $transaction->user?->name,
                    'user_id' => $transaction->user_id,
                    'shift_id' => $transaction->shift_id,
                    'currency' => $currency,
                    'days' => null,
                    'debit' => 0,
                    'credit' => $transaction->amount,
                    'balance' => $runningBalance[$currency]
                ];
            }
        }

        return $entries;
    }
}
