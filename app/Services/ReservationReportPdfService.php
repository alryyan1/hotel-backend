<?php

namespace App\Services;

use App\Models\HotelSetting;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;

/**
 * Builds the "today's arrivals/departures" printable PDF — shared by the
 * on-demand HTTP download (ReservationController::exportTodayPdf) and the
 * scheduled WhatsApp report (SendTodayReservationReports command), so both
 * always produce the exact same document.
 */
class ReservationReportPdfService
{
    /**
     * @return array{filename: string, content: string, count: int}
     */
    public function generate(string $type): array
    {
        $isArrivals = $type === 'arrivals';
        $dateColumn = $isArrivals ? 'check_in_date' : 'check_out_date';

        $reservations = Reservation::whereDate($dateColumn, today())
            ->where('status', '!=', 'cancelled')
            ->with(['customer', 'rooms', 'creationLog.user:id,name'])
            ->orderBy($dateColumn)
            ->get();

        $today = date('d/m/Y');
        $title = $isArrivals ? 'قائمة الوصول اليوم' : 'قائمة المغادرة اليوم';

        $pageWidth = 297;  // A4 landscape width in mm
        $pageHeight = 210; // A4 landscape height in mm
        $leftMargin = 15;
        $rightMargin = 15;
        $topMargin = 15;
        $bottomMargin = 15;

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins($leftMargin, $topMargin, $rightMargin);
        $pdf->SetAutoPageBreak(true, $bottomMargin);
        $pdf->setRTL(true);
        $pdf->AddPage();

        $settings = HotelSetting::first();
        $hotelName = ($settings->official_name ?? null) ?: ($settings->trade_name ?? null);

        // Branded header image (or logo as a fallback), same pattern used across the
        // other printable documents, so this list looks consistent with invoices/ledgers.
        if ($settings && $settings->header_path) {
            $headerImagePath = storage_path('app/public/' . $settings->header_path);
            if (file_exists($headerImagePath)) {
                try {
                    $imageInfo = @getimagesize($headerImagePath);
                    if ($imageInfo !== false) {
                        $aspectRatio = $imageInfo[1] / $imageInfo[0];
                        $headerHeightMM = $pageWidth * $aspectRatio;

                        $pdf->setRTL(false);
                        $pdf->Image($headerImagePath, 0, 0, $pageWidth, $headerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);

                        $pdf->SetY($headerHeightMM + 5);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load header image: ' . $e->getMessage());
                }
            }
        } elseif ($settings && $settings->logo_path) {
            $logoImagePath = storage_path('app/public/' . $settings->logo_path);
            if (file_exists($logoImagePath)) {
                try {
                    $imageInfo = @getimagesize($logoImagePath);
                    if ($imageInfo !== false) {
                        $logoWidthMM = $pageWidth * 0.12;
                        $aspectRatio = $imageInfo[1] / $imageInfo[0];
                        $logoHeightMM = $logoWidthMM * $aspectRatio;

                        $pdf->setRTL(false);
                        $pdf->Image($logoImagePath, ($pageWidth - $logoWidthMM) / 2, $topMargin, $logoWidthMM, $logoHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);

                        $pdf->SetY($topMargin + $logoHeightMM + 5);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load logo image: ' . $e->getMessage());
                }
            }
        }

        if ($hotelName) {
            $pdf->SetFont('arial', 'B', 16);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->Cell(0, 9, $hotelName, 0, 1, 'C');
        }

        $pdf->SetFont('arial', 'B', 15);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->Cell(0, 8, $title, 0, 1, 'C');

        // Thin rule under the title for separation from the summary line below.
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Line($leftMargin, $pdf->GetY() + 1, $pageWidth - $rightMargin, $pdf->GetY() + 1);
        $pdf->Ln(4);

        $pdf->SetFont('arial', '', 10);
        $pdf->SetTextColor(90, 90, 90);
        $summaryWidth = ($pageWidth - $leftMargin - $rightMargin) / 2;
        $pdf->Cell($summaryWidth, 6, 'التاريخ: ' . $today . '  —  عدد الحجوزات: ' . $reservations->count(), 0, 0, 'R');
        $pdf->Cell($summaryWidth, 6, 'تاريخ الطباعة: ' . now()->format('d/m/Y H:i'), 0, 1, 'L');
        $pdf->Ln(4);

        $colId = 18;
        $colGuest = 48;
        $colPhone = 30;
        $colRooms = 35;
        $colCheckIn = 28;
        $colCheckOut = 28;
        $colStatus = 28;
        $colCreatedBy = 32;
        // Total: 18+48+30+35+28+28+28+32 = 247mm (fits A4 landscape minus margins)

        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(45, 55, 72);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetDrawColor(45, 55, 72);

        $pdf->Cell($colId, 9, 'رقم الحجز', 1, 0, 'C', true);
        $pdf->Cell($colGuest, 9, 'اسم العميل', 1, 0, 'C', true);
        $pdf->Cell($colPhone, 9, 'الهاتف', 1, 0, 'C', true);
        $pdf->Cell($colRooms, 9, 'الغرف', 1, 0, 'C', true);
        $pdf->Cell($colCheckIn, 9, 'تاريخ الوصول', 1, 0, 'C', true);
        $pdf->Cell($colCheckOut, 9, 'تاريخ المغادرة', 1, 0, 'C', true);
        $pdf->Cell($colStatus, 9, 'الحالة', 1, 0, 'C', true);
        $pdf->Cell($colCreatedBy, 9, 'سجل بواسطه', 1, 1, 'C', true);

        $statusLabels = [
            'pending' => 'في الانتظار',
            'confirmed' => 'مؤكد',
            'checked_in' => 'تم تسجيل الوصول',
            'checked_out' => 'تم تسجيل المغادرة',
            'cancelled' => 'ملغي',
        ];

        // Status colors mirror the badges used on the reservations list in the app.
        $statusColors = [
            'pending' => [191, 144, 0],
            'confirmed' => [22, 119, 255],
            'checked_in' => [56, 158, 13],
            'checked_out' => [110, 110, 110],
            'cancelled' => [207, 19, 34],
        ];

        $pdf->SetDrawColor(220, 220, 220);
        $rowIndex = 0;
        foreach ($reservations as $reservation) {
            $roomNumbers = $reservation->rooms->pluck('number')->implode(', ');
            $isEvenRow = $rowIndex % 2 === 1;

            $pdf->SetFillColor(248, 249, 250);
            $pdf->SetTextColor(40, 40, 40);
            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell($colId, 8, '#' . $reservation->id, 1, 0, 'C', $isEvenRow);
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell($colGuest, 8, $reservation->customer->name ?? '-', 1, 0, 'C', $isEvenRow);
            $pdf->Cell($colPhone, 8, $reservation->customer->phone ?? '-', 1, 0, 'C', $isEvenRow);
            $pdf->Cell($colRooms, 8, $roomNumbers ?: '-', 1, 0, 'C', $isEvenRow);
            $pdf->Cell($colCheckIn, 8, date('d/m/Y', strtotime($reservation->check_in_date)), 1, 0, 'C', $isEvenRow);
            $pdf->Cell($colCheckOut, 8, date('d/m/Y', strtotime($reservation->check_out_date)), 1, 0, 'C', $isEvenRow);

            $statusColor = $statusColors[$reservation->status] ?? [40, 40, 40];
            $pdf->SetTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
            $pdf->SetFont('arial', 'B', 9);
            $pdf->Cell($colStatus, 8, $statusLabels[$reservation->status] ?? $reservation->status, 1, 0, 'C', $isEvenRow);

            $pdf->SetTextColor(90, 90, 90);
            $pdf->SetFont('arial', '', 9);
            $pdf->Cell($colCreatedBy, 8, $reservation->creationLog?->user?->name ?? 'النظام', 1, 1, 'C', $isEvenRow);

            $rowIndex++;
        }

        $pdf->SetTextColor(0, 0, 0);

        if ($reservations->isEmpty()) {
            $pdf->SetFont('arial', '', 10);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(0, 12, $isArrivals ? 'لا يوجد وصول اليوم' : 'لا يوجد مغادرة اليوم', 1, 1, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        // Footer image, if one is configured, for brand consistency with other documents.
        if ($settings && $settings->footer_path) {
            $footerImagePath = storage_path('app/public/' . $settings->footer_path);
            if (file_exists($footerImagePath)) {
                try {
                    $footerInfo = @getimagesize($footerImagePath);
                    if ($footerInfo !== false) {
                        $footerHeightMM = $pageWidth * ($footerInfo[1] / $footerInfo[0]);
                        $pdf->SetAutoPageBreak(false);
                        $pdf->setRTL(false);
                        $pdf->Image($footerImagePath, 0, $pageHeight - $footerHeightMM - 4, $pageWidth, $footerHeightMM, '', '', '', false, 300, '', false, false, 0, false, false, false);
                        $pdf->setRTL(true);
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to load footer image: ' . $e->getMessage());
                }
            }
        }

        $filename = ($isArrivals ? 'arrivals_' : 'departures_') . date('Y-m-d') . '.pdf';

        return [
            'filename' => $filename,
            'content' => $pdf->Output($filename, 'S'),
            'count' => $reservations->count(),
        ];
    }
}
