<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservationService;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ReservationServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = ReservationService::with(['reservation.customer', 'room', 'service']);
        
        // Optionally filter by reservation
        if ($request->has('reservation_id')) {
            $query->where('reservation_id', $request->get('reservation_id'));
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'room_id' => 'required|exists:rooms,id',
            'service_id' => 'required|exists:services,id',
            'amount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reservationService = ReservationService::create($validated);
        $reservationService->load(['reservation.customer', 'room', 'service']);

        // Always create a debit transaction so the service appears in the customer's ledger
        $reservation = $reservationService->reservation;
        if ($reservation && $reservation->customer_id) {
            Transaction::create([
                'customer_id'            => $reservation->customer_id,
                'reservation_id'         => $reservation->id,
                'reservation_service_id' => $reservationService->id,
                'type'                   => 'debit',
                'amount'                 => $reservationService->amount ?? 0,
                'transaction_date'       => now(),
                'notes'                  => $reservationService->service->name ?? 'خدمة',
            ]);
        }

        return response()->json($reservationService, 201);
    }

    public function show(ReservationService $reservationService)
    {
        return response()->json($reservationService->load(['reservation', 'room', 'service']));
    }

    public function update(Request $request, ReservationService $reservationService)
    {
        $validated = $request->validate([
            'room_id' => 'sometimes|exists:rooms,id',
            'service_id' => 'sometimes|exists:services,id',
            'amount' => 'sometimes|numeric|min:0',
            'payment_method' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reservationService->update($validated);
        $reservationService->refresh()->load(['reservation.customer', 'room', 'service', 'transaction']);

        $newAmount = $reservationService->amount ?? 0;
        $serviceName = $reservationService->service->name ?? 'خدمة';

        if ($reservationService->transaction) {
            // Update existing transaction
            $reservationService->transaction->update([
                'amount' => $newAmount,
                'notes'  => $serviceName,
            ]);
        } else {
            // No transaction yet (old data) — create one now
            $reservation = $reservationService->reservation;
            if ($reservation && $reservation->customer_id) {
                Transaction::create([
                    'customer_id'            => $reservation->customer_id,
                    'reservation_id'         => $reservation->id,
                    'reservation_service_id' => $reservationService->id,
                    'type'                   => 'debit',
                    'amount'                 => $newAmount,
                    'transaction_date'       => now(),
                    'notes'                  => $serviceName,
                ]);
            }
        }

        return response()->json($reservationService);
    }

    public function destroy(ReservationService $reservationService)
    {
        // Delete linked debit transaction before deleting the service
        $reservationService->load('transaction');
        $reservationService->transaction?->delete();

        $reservationService->delete();
        return response()->json(null, 204);
    }

    public function exportPdf(ReservationService $reservationService): Response
    {
        $reservationService->load(['reservation.customer', 'room', 'service']);

        $pageWidth    = 210;
        $pageHeight   = 297;
        $leftMargin   = 15;
        $rightMargin  = 15;
        $topMargin    = 15;
        $bottomMargin = 15;

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('فاتورة خدمة #' . $reservationService->id);
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
        $pdf->Cell(0, 10, 'فاتورة خدمة', 0, 1, 'C');
        $pdf->Ln(4);

        $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري'];
        $customer     = $reservationService->reservation->customer ?? null;
        $availW       = $pageWidth - $leftMargin - $rightMargin; // 180mm
        $labelW       = 55;
        $valueW       = $availW - $labelW; // 125mm

        // ── Customer table ──
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($availW, 8, 'بيانات العميل', 1, 1, 'C', true);

        $rows = [
            'الاسم'  => $customer->name ?? '-',
            'الهاتف' => $customer->phone ?? '-',
        ];
        $pdf->SetFont('arial', '', 10);
        foreach ($rows as $label => $value) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell($labelW, 7, $label, 1, 0, 'R', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($valueW, 7, $value, 1, 1, 'R', true);
        }

        $pdf->Ln(4);

        // ── Service details table ──
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell($availW, 8, 'تفاصيل الخدمة', 1, 1, 'C', true);

        $serviceRows = [
            'رقم الفاتورة'   => '#' . $reservationService->id,
            'التاريخ'        => $reservationService->created_at->format('d/m/Y H:i'),
            'الغرفة'         => $reservationService->room->number ?? '-',
            'الخدمة'         => $reservationService->service->name ?? '-',
            'طريقة الدفع'   => $methodLabels[$reservationService->payment_method] ?? ($reservationService->payment_method ?? '-'),
        ];
        if ($reservationService->notes) {
            $serviceRows['ملاحظات'] = $reservationService->notes;
        }

        $pdf->SetFont('arial', '', 10);
        foreach ($serviceRows as $label => $value) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell($labelW, 7, $label, 1, 0, 'R', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($valueW, 7, $value, 1, 1, 'R', true);
        }

        $pdf->Ln(6);

        // ── Amount row ──
        $pdf->SetFont('arial', 'B', 12);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell($labelW, 10, 'المبلغ المدفوع', 1, 0, 'C', true);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetFont('arial', 'B', 13);
        $pdf->Cell($valueW, 10, number_format($reservationService->amount, 0, '.', ','), 1, 1, 'C', true);

        // Stamp
        $pdf->SetAutoPageBreak(false);
        $pdf->Ln(20);

        if ($settings && $settings->stamp_path) {
            $stampImagePath = storage_path('app/public/' . $settings->stamp_path);
            if (!file_exists($stampImagePath)) $stampImagePath = public_path('storage/' . $settings->stamp_path);
            if (file_exists($stampImagePath)) {
                try {
                    $imageInfo = @getimagesize($stampImagePath);
                    if ($imageInfo !== false) {
                        $stampWidthMM  = 40;
                        $stampHeightMM = $stampWidthMM * ($imageInfo[1] / $imageInfo[0]);
                        $currentY = $pdf->GetY();
                        $pdf->setRTL(false);
                        $pdf->Image($stampImagePath, $leftMargin, $currentY, $stampWidthMM, $stampHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->SetY($currentY + $stampHeightMM + 5);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load stamp: ' . $e->getMessage());
                }
            }
        }

        if ($settings && $settings->e_stamp_path) {
            $eStampImagePath = storage_path('app/public/' . $settings->e_stamp_path);
            if (!file_exists($eStampImagePath)) $eStampImagePath = public_path('storage/' . $settings->e_stamp_path);
            if (file_exists($eStampImagePath)) {
                try {
                    $imageInfo = @getimagesize($eStampImagePath);
                    if ($imageInfo !== false) {
                        $stampWidthMM  = 40;
                        $stampHeightMM = $stampWidthMM * ($imageInfo[1] / $imageInfo[0]);
                        $currentY = $pdf->GetY();
                        $pdf->setRTL(false);
                        $pdf->Image($eStampImagePath, $leftMargin, $currentY, $stampWidthMM, $stampHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->SetY($currentY + $stampHeightMM + 5);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load e-stamp: ' . $e->getMessage());
                }
            }
        }

        if ($settings && $settings->footer_path) {
            $footerImagePath = storage_path('app/public/' . $settings->footer_path);
            if (!file_exists($footerImagePath)) $footerImagePath = public_path('storage/' . $settings->footer_path);
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

        $filename = 'service_invoice_' . $reservationService->id . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
