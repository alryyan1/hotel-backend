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
        foreach ($data['rooms'] as $roomReq) {
            $overlap = Reservation::query()
                ->whereHas('rooms', function ($q) use ($roomReq, $data) {
                    $q->where('rooms.id', $roomReq['id'])
                      ->where(function($p) use ($roomReq, $data) {
                          $ci = $roomReq['check_in_date'] ?? $data['check_in_date'];
                          $co = $roomReq['check_out_date'] ?? $data['check_out_date'];
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
                })->where('reservations.status', '!=', 'cancelled')
                ->exists();
            if ($overlap) {
                return response()->json(['message' => 'Room not available for selected period','room_id' => $roomReq['id']], 422);
            }
        }

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
        $sheet->mergeCells('C' . $currentRow . ':L' . $currentRow);
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
            $sheet->mergeCells('C' . $currentRow . ':L' . $currentRow);
            $sheet->setCellValue('C' . $currentRow, implode(' | ', $details));
            $sheet->getStyle('C' . $currentRow)->applyFromArray([
                'font' => ['size' => 11, 'color' => ['rgb' => '666666']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $currentRow++;
        }

        // Report title
        $currentRow++;
        $sheet->mergeCells('A' . $currentRow . ':L' . $currentRow);
        $sheet->setCellValue('A' . $currentRow, 'تقرير الحجوزات');
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($currentRow)->setRowHeight(25);
        $currentRow++;

        // Report date
        $sheet->mergeCells('A' . $currentRow . ':L' . $currentRow);
        $sheet->setCellValue('A' . $currentRow, 'تاريخ التقرير: ' . date('Y-m-d H:i'));
        $sheet->getStyle('A' . $currentRow)->applyFromArray([
            'font' => ['size' => 10, 'color' => ['rgb' => '666666']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $currentRow++;
        $currentRow++; // Empty row before table

        // Headers
        $headers = ['رقم الحجز', 'العميل', 'الهاتف', 'الغرف', 'تاريخ الوصول', 'تاريخ المغادرة', 'عدد الضيوف', 'الحالة', 'المبلغ الإجمالي', 'المبلغ المدفوع', 'المتبقي', 'الملاحظات'];
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
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
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

        // Status labels
        $statusLabels = [
            'pending' => 'في الانتظار',
            'confirmed' => 'مؤكد',
            'checked_in' => 'تم تسجيل الوصول',
            'checked_out' => 'تم تسجيل المغادرة',
            'cancelled' => 'ملغي'
        ];

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
            $sheet->setCellValue('G' . $row, $reservation->guest_count ?? '');
            $sheet->setCellValue('H' . $row, $statusLabels[$reservation->status] ?? $reservation->status);
            $sheet->setCellValue('I' . $row, number_format($totalAmountValue, 2));
            $sheet->setCellValue('J' . $row, number_format($paidAmountValue, 2));
            $sheet->setCellValue('K' . $row, number_format($balanceValue, 2));
            $sheet->setCellValue('L' . $row, $reservation->notes ?? '');

            // Style data cells
            $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Highlight balance if positive (amount due)
            if ($balanceValue > 0) {
                $sheet->getStyle('K' . $row)->applyFromArray([
                    'font' => ['color' => ['rgb' => 'C00000'], 'bold' => true],
                ]);
            }

            // Alternate row colors for better readability
            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':L' . $row)->applyFromArray([
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
        $sheet->mergeCells('A' . $totalRow . ':H' . $totalRow);
        $sheet->setCellValue('A' . $totalRow, 'الإجمالي');
        $sheet->setCellValue('I' . $totalRow, number_format($totalAmount, 2));
        $sheet->setCellValue('J' . $totalRow, number_format($totalPaid, 2));
        $sheet->setCellValue('K' . $totalRow, number_format($totalBalance, 2));
        $sheet->setCellValue('L' . $totalRow, '');

        $sheet->getStyle('A' . $totalRow . ':L' . $totalRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THICK,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        $sheet->getRowDimension($totalRow)->setRowHeight(25);

        // Auto-size columns
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Set column widths for better printing
        $sheet->getColumnDimension('A')->setWidth(12); // رقم الحجز
        $sheet->getColumnDimension('B')->setWidth(20); // العميل
        $sheet->getColumnDimension('C')->setWidth(15); // الهاتف
        $sheet->getColumnDimension('D')->setWidth(25); // الغرف
        $sheet->getColumnDimension('E')->setWidth(15); // تاريخ الوصول
        $sheet->getColumnDimension('F')->setWidth(15); // تاريخ المغادرة
        $sheet->getColumnDimension('G')->setWidth(12); // عدد الضيوف
        $sheet->getColumnDimension('H')->setWidth(18); // الحالة
        $sheet->getColumnDimension('I')->setWidth(15); // المبلغ الإجمالي
        $sheet->getColumnDimension('J')->setWidth(15); // المبلغ المدفوع
        $sheet->getColumnDimension('K')->setWidth(15); // المتبقي
        $sheet->getColumnDimension('L')->setWidth(30); // الملاحظات

        // Set print area
        $sheet->getPageSetup()->setPrintArea('A1:L' . $totalRow);

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
}
