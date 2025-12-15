<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\ReservationSmsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\HotelSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;
use App\Models\RoomType;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Reservation::with(['customer', 'rooms', 'payments']);

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                // Search in customer name
                $q->whereHas('customer', function($customerQuery) use ($searchTerm) {
                    $customerQuery->where('name', 'like', "%{$searchTerm}%")
                                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                                  ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                // Search in room numbers
                ->orWhereHas('rooms', function($roomQuery) use ($searchTerm) {
                    $roomQuery->where('number', 'like', "%{$searchTerm}%");
                })
                // Search in reservation ID
                ->orWhere('id', 'like', "%{$searchTerm}%");
            });
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all' && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        // Customer filter
        if ($request->has('customer_id') && !empty($request->customer_id)) {
            $query->where('customer_id', $request->customer_id);
        }

        // Date range filter
        if ($request->has('date_from') && !empty($request->date_from) && 
            $request->has('date_to') && !empty($request->date_to)) {
            // Both dates provided: show reservations that overlap with the date range
            $dateFrom = $request->date_from;
            $dateTo = $request->date_to;
            // Reservation overlaps if: check_in_date <= date_to AND check_out_date >= date_from
            $query->where('check_in_date', '<=', $dateTo)
                  ->where('check_out_date', '>=', $dateFrom);
        } else {
            // Only one date provided
            if ($request->has('date_from') && !empty($request->date_from)) {
                $dateFrom = $request->date_from;
                // Show reservations that end on or after this date
                $query->where('check_out_date', '>=', $dateFrom);
            }

            if ($request->has('date_to') && !empty($request->date_to)) {
                $dateTo = $request->date_to;
                // Show reservations that start on or before this date
                $query->where('check_in_date', '<=', $dateTo);
            }
        }

        // Order by ID descending
        $query->orderBy('id', 'desc');

        // Get pagination per page (default 20, max 100)
        $perPage = min($request->get('per_page', 20), 100);

        $reservations = $query->paginate($perPage);
        return response()->json($reservations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required','exists:customers,id'],
            'check_in_date' => ['required','date'],
            'check_out_date' => ['required','date','after:check_in_date'],
            'guest_count' => ['nullable','integer','min:1'],
            'status' => ['nullable', Rule::in(['pending','confirmed','checked_in','checked_out','cancelled'])],
            'notes' => ['nullable','string'],
            'rooms' => ['required','array','min:1'],
            'rooms.*.id' => ['required','exists:rooms,id'],
            'rooms.*.check_in_date' => ['nullable','date'],
            'rooms.*.check_out_date' => ['nullable','date','after_or_equal:rooms.*.check_in_date'],
            'rooms.*.rate' => ['nullable','numeric','min:0'],
            'rooms.*.currency' => ['nullable','string','size:3'],
        ]);

        // Check overlap for each requested room
        // foreach ($data['rooms'] as $roomReq) {
        //     $overlap = Reservation::query()
        //         ->whereHas('rooms', function ($q) use ($roomReq, $data) {
        //             $q->where('rooms.id', $roomReq['id'])
        //               ->where(function($p) use ($roomReq, $data) {
        //                   $ci = $roomReq['check_in_date'] ?? $data['check_in_date'];
        //                   $co = $roomReq['check_out_date'] ?? $data['check_out_date'];
        //                   $p->where(function($x) use ($ci, $co) {
        //                       $x->where('reservation_room.check_in_date', '<', $co)
        //                         ->where('reservation_room.check_out_date', '>', $ci);
        //                   })->orWhere(function($x) use ($ci, $co) {
        //                       $x->whereNull('reservation_room.check_in_date')
        //                         ->whereNull('reservation_room.check_out_date')
        //                         ->where('reservations.check_in_date', '<', $co)
        //                         ->where('reservations.check_out_date', '>', $ci);
        //                   });
        //               });
        //         })->where('reservations.status', '!=', 'cancelled')
        //         ->exists();
        //     if ($overlap) {
        //         return response()->json(['message' => 'Room not available for selected period','room_id' => $roomReq['id']], 422);
        //     }
        // }

        $reservation = Reservation::create([
            'customer_id' => $data['customer_id'],
            'check_in_date' => $data['check_in_date'],
            'check_out_date' => $data['check_out_date'],
            'guest_count' => $data['guest_count'] ?? 1,
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        $syncData = collect($data['rooms'])->mapWithKeys(function ($room) use ($data) {
            return [
                $room['id'] => [
                    'check_in_date' => $room['check_in_date'] ?? $data['check_in_date'],
                    'check_out_date' => $room['check_out_date'] ?? $data['check_out_date'],
                    'rate' => $room['rate'] ?? null,
                    'currency' => $room['currency'] ?? 'USD',
                ],
            ];
        })->toArray();

        $reservation->rooms()->sync($syncData);

        // Send SMS notification
        $smsResult = null;
        try {
            $smsService = app(ReservationSmsService::class);
            $smsResult = $smsService->sendReservationConfirmation($reservation);
            
            if (!$smsResult['success']) {
                Log::warning('Failed to send reservation SMS', [
                    'reservation_id' => $reservation->id,
                    'error' => $smsResult['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SMS service error', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage()
            ]);
            $smsResult = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        $responseData = $reservation->load(['customer','rooms','payments']);
        $responseData->sms_result = $smsResult;

        return response()->json($responseData, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        return response()->json($reservation->load(['customer','rooms','payments']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'customer_id' => ['sometimes','exists:customers,id'],
            'check_in_date' => ['sometimes','date'],
            'check_out_date' => ['sometimes','date','after:check_in_date'],
            'guest_count' => ['sometimes','integer','min:1'],
            'status' => ['sometimes', Rule::in(['pending','confirmed','checked_in','checked_out','cancelled'])],
            'notes' => ['sometimes','string'],
            'rooms' => ['sometimes','array','min:1'],
            'rooms.*.id' => ['required_with:rooms','exists:rooms,id'],
            'rooms.*.check_in_date' => ['nullable','date'],
            'rooms.*.check_out_date' => ['nullable','date','after_or_equal:rooms.*.check_in_date'],
            'rooms.*.rate' => ['nullable','numeric','min:0'],
            'rooms.*.currency' => ['nullable','string','size:3'],
        ]);

        // If dates or rooms changed, re-validate overlaps
        if (isset($data['rooms']) || isset($data['check_in_date']) || isset($data['check_out_date'])) {
            $ciBase = $data['check_in_date'] ?? $reservation->check_in_date;
            $coBase = $data['check_out_date'] ?? $reservation->check_out_date;
            $roomsCheck = $data['rooms'] ?? $reservation->rooms->map(fn($r)=>['id'=>$r->id])->toArray();
            foreach ($roomsCheck as $roomReq) {
                $ci = $roomReq['check_in_date'] ?? $ciBase;
                $co = $roomReq['check_out_date'] ?? $coBase;
                $overlap = Reservation::query()
                    ->where('id', '<>', $reservation->id)
                    ->whereHas('rooms', function ($q) use ($roomReq, $ci, $co) {
                        $q->where('rooms.id', $roomReq['id'])
                          ->where(function($p) use ($ci, $co) {
                              $p->where(function($x) use ($ci, $co) {
                                  $x->where('reservation_room.check_in_date', '<', $co)
                                    ->where('reservation_room.check_out_date', '>', $ci);
                              })->orWhere(function($x) use ($ci, $co) {
                                  $x->whereNull('reservation_room.check_in_date')
                                    ->whereNull('reservation_room.check_out_date')
                                    ->where('reservations.check_in_date', '<', $co)
                                    ->where('reservations.check_out_date', '>', $ci);
                              });
                          });
                    })
                    ->exists();
                if ($overlap) {
                    return response()->json(['message' => 'Room not available for selected period','room_id' => $roomReq['id']], 422);
                }
            }
        }

        $reservation->update($data);

        if (isset($data['rooms'])) {
            $syncData = collect($data['rooms'])->mapWithKeys(function ($room) use ($data, $reservation) {
                $ciBase = $data['check_in_date'] ?? $reservation->check_in_date;
                $coBase = $data['check_out_date'] ?? $reservation->check_out_date;
                return [
                    $room['id'] => [
                        'check_in_date' => $room['check_in_date'] ?? $ciBase,
                        'check_out_date' => $room['check_out_date'] ?? $coBase,
                        'rate' => $room['rate'] ?? null,
                        'currency' => $room['currency'] ?? 'USD',
                    ],
                ];
            })->toArray();
            $reservation->rooms()->sync($syncData);
        }

        return response()->json($reservation->load(['customer','rooms','payments']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        $reservation->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function confirm(Reservation $reservation)
    {
        if ($reservation->status !== 'pending') {
            return response()->json(['message' => 'Only pending reservations can be confirmed'], 422);
        }
        $reservation->update(['status' => 'confirmed']);

        // Send SMS notification for confirmation
        $smsResult = null;
        try {
            $smsService = app(ReservationSmsService::class);
            $smsResult = $smsService->sendReservationConfirmationSms($reservation);
            
            if (!$smsResult['success']) {
                Log::warning('Failed to send reservation confirmation SMS', [
                    'reservation_id' => $reservation->id,
                    'error' => $smsResult['error'] ?? 'Unknown error'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SMS service error during confirmation', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage()
            ]);
            $smsResult = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        $responseData = $reservation->load(['customer', 'rooms', 'payments']);
        $responseData->sms_result = $smsResult;

        return response()->json($responseData);
    }

    public function checkIn(Reservation $reservation)
    {
        if (! in_array($reservation->status, ['confirmed'])) {
            return response()->json(['message' => 'Reservation must be confirmed to check in'], 422);
        }

        // Update reservation status to checked_in
        $reservation->update(['status' => 'checked_in']);

        return response()->json($reservation->fresh()->load(['customer', 'rooms', 'payments']));
    }

    public function checkOut(Reservation $reservation)
    {
        if ($reservation->status !== 'checked_in') {
            return response()->json(['message' => 'Reservation must be checked in to check out'], 422);
        }

        DB::transaction(function () use ($reservation) {
            // Create cleaning notification for checkout
            foreach ($reservation->rooms as $room) {
                \App\Models\CleaningNotification::create([
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'type' => 'checkout',
                    'status' => 'pending',
                    'notified_at' => now(),
                ]);
            }
            $reservation->update(['status' => 'checked_out']);
        });

        return response()->json($reservation->fresh()->load(['customer', 'rooms', 'payments']));
    }

    public function cancel(Reservation $reservation)
    {
        if (in_array($reservation->status, ['checked_in','checked_out'])) {
            return response()->json(['message' => 'Cannot cancel after check-in'], 422);
        }
        $reservation->update(['status' => 'cancelled']);
        return response()->json($reservation->load(['customer', 'rooms', 'payments']));
    }

    /**
     * Export reservations to Excel
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $query = Reservation::with(['customer', 'rooms', 'payments']);

        // Apply the same filters as index method
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->whereHas('customer', function($customerQuery) use ($searchTerm) {
                    $customerQuery->where('name', 'like', "%{$searchTerm}%")
                                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                                  ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                ->orWhereHas('rooms', function($roomQuery) use ($searchTerm) {
                    $roomQuery->where('number', 'like', "%{$searchTerm}%");
                })
                ->orWhere('id', 'like', "%{$searchTerm}%");
            });
        }

        if ($request->has('status') && $request->status !== 'all' && !empty($request->status)) {
            $query->where('status', $request->status);
        }

        if ($request->has('customer_id') && !empty($request->customer_id)) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->has('date_from') && !empty($request->date_from) && 
            $request->has('date_to') && !empty($request->date_to)) {
            $dateFrom = $request->date_from;
            $dateTo = $request->date_to;
            $query->where('check_in_date', '<=', $dateTo)
                  ->where('check_out_date', '>=', $dateFrom);
        } else {
            if ($request->has('date_from') && !empty($request->date_from)) {
                $query->where('check_out_date', '>=', $request->date_from);
            }

            if ($request->has('date_to') && !empty($request->date_to)) {
                $query->where('check_in_date', '<=', $request->date_to);
            }
        }

        $reservations = $query->orderBy('id', 'desc')->get();

        // Get hotel settings
        $hotelSettings = HotelSetting::first();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الحجوزات');
        
        // Set RTL (Right-to-Left) direction
        $sheet->setRightToLeft(true);

        // Set print settings
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // Set margins for printing
        $sheet->getPageMargins()
            ->setTop(0.5)
            ->setRight(0.5)
            ->setBottom(0.5)
            ->setLeft(0.5);

        // Header row starts at row 5 (after logo and hotel info)
        $currentRow = 1;

        // Add logo if available
        if ($hotelSettings && $hotelSettings->logo_path && Storage::disk('public')->exists($hotelSettings->logo_path)) {
            $drawing = new Drawing();
            $logoPath = storage_path('app/public/' . $hotelSettings->logo_path);
            $drawing->setPath($logoPath);
            $drawing->setHeight(80);
            $drawing->setWidth(80);
            $drawing->setCoordinates('A' . $currentRow);
            $drawing->setOffsetX(10);
            $drawing->setOffsetY(10);
            $drawing->setWorksheet($sheet);
            
            // Merge cells for logo area
            $sheet->mergeCells('A' . $currentRow . ':B' . ($currentRow + 2));
        }

        // Hotel information header
        $hotelName = $hotelSettings?->official_name ?? $hotelSettings?->trade_name ?? 'الفندق';
        $hotelAddress = trim(($hotelSettings?->address_line ?? '') . ' ' . ($hotelSettings?->city ?? ''));
        $hotelPhone = $hotelSettings?->phone ?? '';
        $hotelPhone2 = $hotelSettings?->phone_2 ?? '';
        $hotelEmail = $hotelSettings?->email ?? '';

        // Set hotel name (centered, spanning multiple columns)
        $sheet->mergeCells('C' . $currentRow . ':I' . $currentRow);
        $sheet->setCellValue('C' . $currentRow, $hotelName);
        $sheet->getStyle('C' . $currentRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(30);
        $currentRow++;

        // Hotel details
        $details = [];
        if ($hotelAddress) $details[] = $hotelAddress;
        if ($hotelPhone) $details[] = 'هاتف: ' . $hotelPhone;
        if ($hotelPhone2) $details[] = 'هاتف 2: ' . $hotelPhone2;
        if ($hotelEmail) $details[] = 'البريد: ' . $hotelEmail;

        if (!empty($details)) {
            $sheet->mergeCells('C' . $currentRow . ':I' . $currentRow);
            $sheet->setCellValue('C' . $currentRow, implode(' | ', $details));
            $sheet->getStyle('C' . $currentRow)->applyFromArray([
                'font' => ['size' => 11, 'color' => ['rgb' => '666666']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $currentRow++;
        }

        // Report title
        $currentRow++;
        $sheet->mergeCells('A' . $currentRow . ':I' . $currentRow);
        $sheet->setCellValue('A' . $currentRow, 'تقرير الحجوزات');
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(25);
        $currentRow++;

        // Report date
        $sheet->mergeCells('A' . $currentRow . ':I' . $currentRow);
        $sheet->setCellValue('A' . $currentRow, 'تاريخ التقرير: ' . date('Y-m-d H:i'));
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '666666']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $currentRow++;
        $currentRow++; // Empty row before table

        // Headers (removed: عدد الضيوف, الحالة, الملاحظات)
        $headers = ['رقم الحجز', 'العميل', 'الهاتف', 'الغرف', 'تاريخ الوصول', 'تاريخ المغادرة', 'المبلغ الإجمالي', 'المبلغ المدفوع', 'المتبقي'];
        $headerRow = $currentRow;
        $col = 'A';

        foreach ($headers as $header) {
            $sheet->setCellValue($col . $headerRow, $header);
            $sheet->getStyle($col . $headerRow)->applyFromArray([
                'font' => ['color' => ['rgb' => 'FFFFFF'], 'bold' => true, 'size' => 11],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ]);
            $col++;
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(25);

        // Data rows
        $row = $currentRow + 1;
        $totalAmount = 0;
        $totalPaid = 0;
        $totalBalance = 0;

        foreach ($reservations as $reservation) {
            $rooms = $reservation->rooms->map(function($room) {
                return 'غرفة ' . $room->number;
            })->implode(', ');

            $totalAmountValue = $reservation->total_amount ?? 0;
            $paidAmountValue = $reservation->paid_amount ?? 0;
            $balanceValue = $totalAmountValue - $paidAmountValue;

            $totalAmount += $totalAmountValue;
            $totalPaid += $paidAmountValue;
            $totalBalance += $balanceValue;

            $sheet->setCellValue('A' . $row, $reservation->id);
            $sheet->setCellValue('B' . $row, $reservation->customer->name ?? '');
            $sheet->setCellValue('C' . $row, $reservation->customer->phone ?? '');
            $sheet->setCellValue('D' . $row, $rooms);
            $sheet->setCellValue('E' . $row, $reservation->check_in_date ? date('Y-m-d', strtotime($reservation->check_in_date)) : '');
            $sheet->setCellValue('F' . $row, $reservation->check_out_date ? date('Y-m-d', strtotime($reservation->check_out_date)) : '');
            $sheet->setCellValue('G' . $row, number_format($totalAmountValue, 2));
            $sheet->setCellValue('H' . $row, number_format($paidAmountValue, 2));
            $sheet->setCellValue('I' . $row, number_format($balanceValue, 2));

            // Style data cells
            $sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Highlight balance if positive (amount due)
            if ($balanceValue > 0) {
                $sheet->getStyle('I' . $row)->applyFromArray([
                    'font' => ['color' => ['rgb' => 'C00000'], 'bold' => true],
                ]);
            }

            // Alternate row colors for better readability
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':I' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2']
                    ],
                ]);
            }

            $row++;
        }

        // Total row
        $totalRow = $row;
        $sheet->mergeCells('A' . $totalRow . ':F' . $totalRow);
        $sheet->setCellValue('A' . $totalRow, 'الإجمالي');
        $sheet->setCellValue('G' . $totalRow, number_format($totalAmount, 2));
        $sheet->setCellValue('H' . $totalRow, number_format($totalPaid, 2));
        $sheet->setCellValue('I' . $totalRow, number_format($totalBalance, 2));

        $sheet->getStyle('A' . $totalRow . ':I' . $totalRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        $sheet->getRowDimension($totalRow)->setRowHeight(25);

        // Auto-size columns
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Set column widths for better printing
        $sheet->getColumnDimension('A')->setWidth(12); // رقم الحجز
        $sheet->getColumnDimension('B')->setWidth(20); // العميل
        $sheet->getColumnDimension('C')->setWidth(15); // الهاتف
        $sheet->getColumnDimension('D')->setWidth(25); // الغرف
        $sheet->getColumnDimension('E')->setWidth(15); // تاريخ الوصول
        $sheet->getColumnDimension('F')->setWidth(15); // تاريخ المغادرة
        $sheet->getColumnDimension('G')->setWidth(15); // المبلغ الإجمالي
        $sheet->getColumnDimension('H')->setWidth(15); // المبلغ المدفوع
        $sheet->getColumnDimension('I')->setWidth(15); // المتبقي

        // Set print area
        $sheet->getPageSetup()->setPrintArea('A1:I' . $totalRow);

        $filename = 'reservations_export_' . date('Y-m-d_His') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        
        return new StreamedResponse(function() use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Generate invoice PDF for a reservation
     */
    public function exportInvoicePdf(Reservation $reservation): Response
    {
        // Load reservation with relations
        $reservation->load(['customer', 'rooms.type', 'payments']);

        // Get room types for pricing
        $roomTypes = RoomType::all()->keyBy('id');

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle('فاتورة الحجز #' . $reservation->id);
        $pdf->SetSubject('Reservation Invoice');

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
        $pdf->Cell(0, 10, 'فاتورة الحجز', 0, 1, 'C');
        $pdf->Ln(5);

        // Reservation Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'معلومات الحجز', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $reservationInfo = [
            'رقم الحجز: #' . $reservation->id,
            'تاريخ الوصول: ' . date('d/m/Y', strtotime($reservation->check_in_date)),
            'تاريخ المغادرة: ' . date('d/m/Y', strtotime($reservation->check_out_date)),
            'عدد الضيوف: ' . ($reservation->guest_count ?? 1),
        ];

        foreach ($reservationInfo as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Customer Information
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'بيانات العميل', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $customerInfo = [
            'الاسم: ' . $reservation->customer->name,
            $reservation->customer->phone ? 'الهاتف: ' . $reservation->customer->phone : null,
            $reservation->customer->national_id ? 'الرقم الوطني: ' . $reservation->customer->national_id : null,
        ];

        foreach (array_filter($customerInfo) as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

        $pdf->Ln(5);

        // Calculate days
        $checkIn = new \DateTime($reservation->check_in_date);
        $checkOut = new \DateTime($reservation->check_out_date);
        $interval = $checkIn->diff($checkOut);
        $days = max(1, $interval->days);

        // Calculate total amount
        $totalAmount = 0;
        $roomDetails = [];

        foreach ($reservation->rooms as $room) {
            $basePrice = ($room->type && $room->type->base_price)
                ? $room->type->base_price
                : ($roomTypes[$room->room_type_id]->base_price ?? 0);
            $roomAmount = $days * $basePrice;
            $totalAmount += $roomAmount;
            $roomDetails[] = [
                'name' => 'غرفة ' . $room->number,
                'days' => $days,
                'price_per_day' => $basePrice,
                'total' => $roomAmount
            ];
        }

        // Invoice Table Header
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        $colDescription = 80;
        $colDays = 30;
        $colPrice = 40;
        $colTotal = 40;

        $pdf->Cell($colDescription, 8, 'الوصف', 1, 0, 'C', true);
        $pdf->Cell($colDays, 8, 'عدد الأيام', 1, 0, 'C', true);
        $pdf->Cell($colPrice, 8, 'السعر/يوم', 1, 0, 'C', true);
        $pdf->Cell($colTotal, 8, 'الإجمالي', 1, 1, 'C', true);

        // Invoice Items
        $pdf->SetFont('arial', '', 9);
        foreach ($roomDetails as $room) {
            $pdf->Cell($colDescription, 7, $room['name'], 1, 0, 'R');
            $pdf->Cell($colDays, 7, $room['days'], 1, 0, 'C');
            $pdf->Cell($colPrice, 7, number_format($room['price_per_day'], 0, '.', ','), 1, 0, 'C');
            $pdf->Cell($colTotal, 7, number_format($room['total'], 0, '.', ','), 1, 1, 'C');
        }

        // Total Row
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $totalColSpan = $colDescription + $colDays + $colPrice;
        $pdf->Cell($totalColSpan, 8, 'الإجمالي', 1, 0, 'R', true);
        $pdf->Cell($colTotal, 8, number_format($totalAmount, 0, '.', ','), 1, 1, 'C', true);

        // Calculate paid amount
        $paidAmount = $reservation->payments->sum('amount');
        $remainingAmount = $totalAmount - $paidAmount;

        $pdf->Ln(5);

        // Payment Summary
        $pdf->SetFont('arial', 'B', 12);
        $pdf->Cell(0, 8, 'ملخص الدفع', 0, 1, 'R');
        $pdf->SetFont('arial', '', 10);

        $paymentInfo = [
            'المبلغ الإجمالي: ' . number_format($totalAmount, 0, '.', ',') . ' ',
            'المبلغ المدفوع: ' . number_format($paidAmount, 0, '.', ',') . ' ',
            'المبلغ المتبقي: ' . number_format($remainingAmount, 0, '.', ',') . ' ',
        ];

        foreach ($paymentInfo as $info) {
            $pdf->Cell(0, 6, $info, 0, 1, 'R');
        }

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
        $filename = 'invoice_reservation_' . $reservation->id . '_' . date('Y-m-d') . '.pdf';
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
