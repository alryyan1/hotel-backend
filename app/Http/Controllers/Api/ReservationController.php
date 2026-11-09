<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\ReservationStatusLog;
use App\Models\Transaction;
use App\Services\ReservationSmsService;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\HotelSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Response;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationSmsService $smsService,
        private WhatsAppCloudApiService $whatsappService
    ) {}

    /**
     * Record a status transition (who changed it and when) for the reservation
     * timeline shown in the UI.
     */
    private function logStatusChange(Reservation $reservation, ?string $fromStatus, string $toStatus, ?int $userId): void
    {
        ReservationStatusLog::create([
            'reservation_id' => $reservation->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $userId,
        ]);
    }

    /**
     * Record a room transfer (old room -> new room) for the reservation timeline.
     */
    private function logRoomTransfer(Reservation $reservation, string $oldRoomNumber, string $newRoomNumber, string $fromDate, ?int $userId): void
    {
        ReservationStatusLog::create([
            'reservation_id' => $reservation->id,
            'notes' => "تحويل غرفة: من {$oldRoomNumber} إلى {$newRoomNumber} اعتباراً من {$fromDate}",
            'user_id' => $userId,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Reservation::with(['customer', 'rooms.type', 'transactions', 'creationLog.user:id,name'])
            ->withExists(['statusLogs as has_room_transfer' => fn ($q) => $q->whereNotNull('notes')]);

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                // Search in customer name
                $q->whereHas('customer', function ($customerQuery) use ($searchTerm) {
                    $customerQuery->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('phone', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                    // Search in room numbers
                    ->orWhereHas('stayRooms', function ($roomQuery) use ($searchTerm) {
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

        // Filter by reservation creation date (created_at) if provided
        if ($request->has('created_at_date') && !empty($request->created_at_date)) {
            $start = \Carbon\Carbon::parse($request->created_at_date)->setTime(12, 0, 0);
            $end   = \Carbon\Carbon::parse($request->created_at_date)->addDay()->setTime(11, 59, 59);
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Exact-day filter for reservations arriving on a specific date (e.g. "today")
        if ($request->filled('checkin_date')) {
            $query->whereDate('check_in_date', $request->checkin_date);
        }

        // Exact-day filter for reservations departing on a specific date (e.g. "today")
        if ($request->filled('checkout_date')) {
            $query->whereDate('check_out_date', $request->checkout_date);
        }

        // Date range filter based on check-in / check-out dates
        if (
            $request->has('date_from') && !empty($request->date_from) &&
            $request->has('date_to') && !empty($request->date_to)
        ) {
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

        // Allow callers to request the full, unpaginated result set
        if ($request->get('per_page') === 'all') {
            return response()->json($query->get());
        }

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
            'customer_id' => ['required', 'exists:customers,id'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'currency' => ['nullable', Rule::in(['USD', 'SDG'])],
            'status' => ['nullable', Rule::in(['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'])],
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*.id' => ['required', 'exists:rooms,id'],
            'rooms.*.check_in_date' => ['nullable', 'date'],
            'rooms.*.check_out_date' => ['nullable', 'date', 'after:rooms.*.check_in_date'],
            'rooms.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        // The whole reservation bills in a single currency, fixed at booking time.
        $currency = $data['currency'] ?? 'SDG';

        // Rooms without a USD price can only be billed in SDG.
        if ($currency === 'USD' && \App\Models\Room::whereIn('id', collect($data['rooms'])->pluck('id'))->whereNull('price_usd')->exists()) {
            $currency = 'SDG';
        }

        // Check overlap for each requested room
        foreach ($data['rooms'] as $roomReq) {
            $overlap = Reservation::query()
                ->whereHas('occupyingRooms', function ($q) use ($roomReq, $data) {
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
                })->whereNotIn('reservations.status', ['cancelled', 'checked_out'])
                ->exists();
            if ($overlap) {
                return response()->json(['message' => 'Room not available for selected period','room_id' => $roomReq['id']], 422);
            }
        }

        $roomsById = \App\Models\Room::whereIn('id', collect($data['rooms'])->pluck('id'))->get()->keyBy('id');

        // Each room gets its own reservation (own number, debit and lifecycle);
        // all-or-nothing so a failure never leaves only some of the rooms booked.
        $reservations = DB::transaction(function () use ($data, $roomsById, $currency, $request) {
            $created = collect();

            foreach ($data['rooms'] as $roomReq) {
                $room = $roomsById[$roomReq['id']];
                $roomCheckIn = $roomReq['check_in_date'] ?? $data['check_in_date'];
                $roomCheckOut = $roomReq['check_out_date'] ?? $data['check_out_date'];
                $days = max(1, (new \DateTime($roomCheckIn))->diff(new \DateTime($roomCheckOut))->days);

                // Freeze the price used at booking time
                $basePrice = $roomReq['rate'] ?? $room->priceFor($currency);
                $roomAmount = $days * $basePrice;

                $reservation = Reservation::create([
                    'customer_id' => $data['customer_id'],
                    'check_in_date' => $roomCheckIn,
                    'check_out_date' => $roomCheckOut,
                    'currency' => $currency,
                    'status' => $data['status'] ?? 'pending',
                ]);

                $this->logStatusChange($reservation, null, $reservation->status, $request->user()?->id);

                $reservation->rooms()->sync([
                    $room->id => [
                        'check_in_date' => $roomCheckIn,
                        'check_out_date' => $roomCheckOut,
                        'rate' => $basePrice,
                        'currency' => $currency,
                    ],
                ]);

                Transaction::create([
                    'customer_id'      => $reservation->customer_id,
                    'reservation_id'   => $reservation->id,
                    'room_id'          => $room->id,
                    'type'             => 'debit',
                    'amount'           => $roomAmount,
                    'rate'             => $basePrice,
                    'nights'           => $days,
                    'check_in_date'    => $roomCheckIn,
                    'check_out_date'   => $roomCheckOut,
                    'currency'         => $currency,
                    'transaction_date' => $reservation->check_in_date,
                    'reference'        => 'RES-' . $reservation->id . '-ROOM-' . $room->id,
                    'notes'            => 'غرفة ' . $room->number,
                ]);

                $reservation->update(['total_amount' => $roomAmount]);

                $created->push($reservation->load(['customer', 'rooms', 'transactions']));
            }

            return $created;
        });

        // The first reservation stays the top-level body for existing clients;
        // `reservations` lists every reservation created (one per room).
        return response()->json(
            $reservations->first()->toArray() + ['reservations' => $reservations->values()->toArray()],
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        return response()->json($reservation->load(['customer', 'rooms.type', 'transactions']));
    }

    /**
     * Status change timeline for the reservation: who changed it and when,
     * oldest first.
     */
    public function statusLogs(Reservation $reservation)
    {
        return response()->json(
            $reservation->statusLogs()->with('user:id,name')->oldest('id')->get()
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'exists:customers,id'],
            'check_in_date' => ['sometimes', 'date'],
            'check_out_date' => ['sometimes', 'date', 'after:check_in_date'],
            'status' => ['sometimes', Rule::in(['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'])],
            'rooms' => ['sometimes', 'array', 'min:1'],
            'rooms.*.id' => ['required_with:rooms', 'exists:rooms,id'],
            'rooms.*.check_in_date' => ['nullable', 'date'],
            'rooms.*.check_out_date' => ['nullable', 'date', 'after:rooms.*.check_in_date'],
            'rooms.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        // A room can appear only once per reservation, and its transferred row must stay
        if (isset($data['rooms']) && collect($data['rooms'])->pluck('id')->intersect($reservation->transferredRooms->pluck('id'))->isNotEmpty()) {
            return response()->json(['message' => 'لا يمكن إعادة غرفة تم التحويل منها إلى نفس الحجز'], 422);
        }

        // If dates or rooms changed, re-validate overlaps
        if (isset($data['rooms']) || isset($data['check_in_date']) || isset($data['check_out_date'])) {
            $ciBase = $data['check_in_date'] ?? $reservation->check_in_date;
            $coBase = $data['check_out_date'] ?? $reservation->check_out_date;
            $roomsCheck = $data['rooms'] ?? $reservation->rooms->map(fn($r) => ['id' => $r->id])->toArray();
            foreach ($roomsCheck as $roomReq) {
                $ci = $roomReq['check_in_date'] ?? $ciBase;
                $co = $roomReq['check_out_date'] ?? $coBase;
                $overlap = Reservation::query()
                    ->where('id', '<>', $reservation->id)
                    ->whereNotIn('status', ['cancelled', 'checked_out'])
                    ->whereHas('occupyingRooms', function ($q) use ($roomReq, $ci, $co) {
                        $q->where('rooms.id', $roomReq['id'])
                            ->where(function ($p) use ($ci, $co) {
                                $p->where(function ($x) use ($ci, $co) {
                                    $x->where('reservation_room.check_in_date', '<', $co)
                                        ->where('reservation_room.check_out_date', '>', $ci);
                                })->orWhere(function ($x) use ($ci, $co) {
                                    $x->whereNull('reservation_room.check_in_date')
                                        ->whereNull('reservation_room.check_out_date')
                                        ->where('reservations.check_in_date', '<', $co)
                                        ->where('reservations.check_out_date', '>', $ci);
                                });
                            });
                    })
                    ->exists();
                if ($overlap) {
                    return response()->json(['message' => 'Room not available for selected period', 'room_id' => $roomReq['id']], 422);
                }
            }
        }

        $reservation->update($data);

        if (isset($data['rooms'])) {
            $roomsById = \App\Models\Room::whereIn('id', collect($data['rooms'])->pluck('id'))->get()->keyBy('id');

            $syncData = collect($data['rooms'])->mapWithKeys(function ($room) use ($data, $reservation, $roomsById) {
                $ciBase = $data['check_in_date'] ?? $reservation->check_in_date;
                $coBase = $data['check_out_date'] ?? $reservation->check_out_date;
                $roomModel = $roomsById[$room['id']] ?? null;
                $frozenRate = $room['rate'] ?? ($roomModel ? $roomModel->priceFor($reservation->currency) : 0);
                return [
                    $room['id'] => [
                        'check_in_date' => $room['check_in_date'] ?? $ciBase,
                        'check_out_date' => $room['check_out_date'] ?? $coBase,
                        'rate' => $frozenRate,
                        'currency' => $reservation->currency,
                    ],
                ];
            })->toArray();
            $reservation->rooms()->sync($syncData);
        }

        return response()->json($reservation->load(['customer', 'rooms.type', 'transactions']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        DB::transaction(function () use ($reservation) {
            Transaction::where('reservation_id', $reservation->id)->delete();
            \App\Models\Payment::where('reservation_id', $reservation->id)->delete();
            $reservation->delete();
        });
        return response()->json(['message' => 'Deleted']);
    }

    public function confirm(Request $request, Reservation $reservation)
    {
        if ($reservation->status !== 'pending') {
            return response()->json(['message' => 'Only pending reservations can be confirmed'], 422);
        }
        $fromStatus = $reservation->status;
        $reservation->update(['status' => 'confirmed']);
        $this->logStatusChange($reservation, $fromStatus, 'confirmed', $request->user()?->id);

        $notificationSettings = HotelSetting::first();
        $smsEnabled = $notificationSettings->sms_notifications_enabled ?? true;
        $whatsappEnabled = $notificationSettings->whatsapp_notifications_enabled ?? true;

        $smsResult = $smsEnabled
            ? $this->smsService->sendReservationConfirmationSms($reservation)
            : ['success' => false, 'error' => 'SMS notifications are disabled', 'skipped' => true];
        $whatsappResult = $whatsappEnabled
            ? $this->whatsappService->sendReservationConfirmationTemplate($reservation)
            : ['success' => false, 'error' => 'WhatsApp notifications are disabled', 'skipped' => true];

        $responseData = $reservation->load(['customer', 'rooms', 'payments']);
        $responseData->sms_result = $smsResult;
        $responseData->whatsapp_result = $whatsappResult;

        $customerPhone = $responseData->customer->phone ?? null;
        $responseData->notifications = [
            'sms' => [
                'enabled' => $smsEnabled,
                'recipients' => ($smsEnabled && $customerPhone) ? [[
                    'phone' => $customerPhone,
                    'label' => 'العميل',
                    'success' => (bool) ($smsResult['success'] ?? false),
                    'error' => $smsResult['error'] ?? null,
                ]] : [],
            ],
            'whatsapp' => [
                'enabled' => $whatsappEnabled,
                'recipients' => $whatsappEnabled ? ($whatsappResult['recipients'] ?? []) : [],
            ],
        ];

        return response()->json($responseData);
    }

    /**
     * Manually trigger WhatsApp checkout reminders for every reservation checking out
     * today that hasn't been reminded yet. Admin-triggered from the UI rather than a
     * scheduled job, since this environment has no cron wired up for the scheduler.
     */
    public function sendCheckoutReminders()
    {
        $settings = HotelSetting::first();
        if (!($settings->whatsapp_notifications_enabled ?? true)) {
            return response()->json(['message' => 'إشعارات واتساب معطّلة من الإعدادات', 'sent' => 0, 'total' => 0], 422);
        }

        $reservations = Reservation::whereDate('check_out_date', today())
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->whereNull('checkout_reminder_sent_at')
            ->with('customer')
            ->get();

        $sent = 0;
        $notified = [];
        $failures = [];

        foreach ($reservations as $reservation) {
            $result = $this->whatsappService->sendCheckoutReminderTemplate($reservation);

            if ($result['success']) {
                $reservation->update(['checkout_reminder_sent_at' => now()]);
                $notified[] = [
                    'reservation_id' => $reservation->id,
                    'checkout_reminder_sent_at' => $reservation->checkout_reminder_sent_at,
                ];
                $sent++;
            } else {
                $failures[] = ['reservation_id' => $reservation->id, 'error' => $result['error'] ?? 'unknown error'];
            }
        }

        return response()->json([
            'sent' => $sent,
            'total' => $reservations->count(),
            'notified' => $notified,
            'failures' => $failures,
        ]);
    }

    public function checkIn(Request $request, Reservation $reservation)
    {
        if (! in_array($reservation->status, ['confirmed'])) {
            return response()->json(['message' => 'Reservation must be confirmed to check in'], 422);
        }

        // Check if any rooms are already occupied by reservations with status 'checked_in'
        // Only prevent check-in if there's a conflicting reservation with status 'checked_in'
        // Allow check-in even if there are pending/confirmed reservations for the same room
        $reservation->load('rooms');
        $occupiedRooms = [];

        foreach ($reservation->rooms as $room) {
            // Find other reservations that have this room and status is 'checked_in'
            $conflictingReservation = \App\Models\Reservation::query()
                ->where('id', '!=', $reservation->id)
                ->where('status', 'checked_in') // Only check for checked_in status
                ->whereHas('rooms', function ($q) use ($room) {
                    $q->where('rooms.id', $room->id);
                })
                ->with('customer:id,name')
                ->first();

            if ($conflictingReservation) {
                $occupiedRooms[] = [
                    'room_id' => $room->id,
                    'room_number' => $room->number,
                    'conflicting_reservation' => [
                        'id' => $conflictingReservation->id,
                        'customer_name' => $conflictingReservation->customer->name ?? 'غير معروف',
                        'check_in_date' => $conflictingReservation->check_in_date,
                        'check_out_date' => $conflictingReservation->check_out_date,
                        'status' => $conflictingReservation->status,
                    ],
                ];
            }
        }

        if (!empty($occupiedRooms)) {
            return response()->json([
                'message' => 'Cannot check in: Some rooms are already occupied',
                'occupied_rooms' => $occupiedRooms,
            ], 422);
        }

        // Update reservation status to checked_in
        $fromStatus = $reservation->status;
        $reservation->update(['status' => 'checked_in']);
        $this->logStatusChange($reservation, $fromStatus, 'checked_in', $request->user()?->id);

        return response()->json($reservation->fresh()->load(['customer', 'rooms', 'transactions']));
    }

    public function checkOut(Request $request, Reservation $reservation)
    {
        if ($reservation->status !== 'checked_in') {
            return response()->json(['message' => 'Reservation must be checked in to check out'], 422);
        }

        $now   = now(); // Africa/Khartoum
        $today = $now->toDateString();

        // After noon: today's hotel day is already running → charge today, refund starts tomorrow
        // Before noon: today's hotel day hasn't started → refund starts today
        $effectiveCheckoutDate = $now->hour >= 12
            ? $now->copy()->addDay()->toDateString()
            : $today;

        $scheduledCheckOut = $reservation->check_out_date instanceof \Carbon\Carbon
            ? $reservation->check_out_date->toDateString()
            : substr($reservation->check_out_date, 0, 10);

        $checkInDateStr = $reservation->check_in_date instanceof \Carbon\Carbon
            ? $reservation->check_in_date->toDateString()
            : substr($reservation->check_in_date, 0, 10);

        // Never let an early-checkout backdate check_out_date earlier than
        // check_in_date (can happen if check-in itself was done ahead of its
        // scheduled date, e.g. an early/same-day check-in followed by checkout).
        $newCheckOutDate = max($today, $checkInDateStr);

        $isEarlyCheckout = $effectiveCheckoutDate < $scheduledCheckOut;
        $refundAmount    = 0;
        $remainingDays   = 0;

        if ($isEarlyCheckout) {
            $effectiveDate = new \DateTime($effectiveCheckoutDate);
            $scheduledDate = new \DateTime($scheduledCheckOut);
            $remainingDays = $effectiveDate->diff($scheduledDate)->days;

            $reservation->load(['rooms.type']);
            foreach ($reservation->rooms as $room) {
                // Refund at the rate of the most recent priced segment for this room
                // (e.g. an extension's new price), falling back to the frozen booking
                // rate for reservations/rooms with no per-room transaction history.
                $latestSegment = Transaction::where('reservation_id', $reservation->id)
                    ->where('room_id', $room->id)
                    ->where('type', 'debit')
                    ->orderByDesc('check_out_date')
                    ->orderByDesc('id')
                    ->first();

                $rate = $latestSegment?->rate ?? $room->pivot->rate ?? $room->priceFor($reservation->currency);
                $refundAmount += $remainingDays * (float) $rate;
            }
        }

        DB::transaction(function () use ($reservation, $today, $newCheckOutDate, $isEarlyCheckout, $refundAmount, $remainingDays, $scheduledCheckOut) {
            foreach ($reservation->rooms as $room) {
                \App\Models\CleaningNotification::create([
                    'room_id' => $room->id,
                    'reservation_id' => $reservation->id,
                    'type' => 'checkout',
                    'status' => 'pending',
                    'notified_at' => now(),
                ]);
            }

            $updateData = ['status' => 'checked_out'];

            if ($isEarlyCheckout) {
                $updateData['check_out_date'] = $newCheckOutDate;

                // Release the rooms too: availability checks read the per-room dates
                foreach ($reservation->rooms as $room) {
                    if (substr((string) $room->pivot->check_out_date, 0, 10) > $newCheckOutDate) {
                        $reservation->rooms()->updateExistingPivot($room->id, ['check_out_date' => $newCheckOutDate]);
                    }
                }

                if ($refundAmount > 0) {
                    Transaction::create([
                        'customer_id' => $reservation->customer_id,
                        'reservation_id' => $reservation->id,
                        'type' => 'refund',
                        'amount' => $refundAmount,
                        'currency' => $reservation->currency,
                        'method' => request('refund_method', 'cash'),
                        'transaction_date' => $today,
                        'notes' => 'مغادرة مبكرة - استرجاع ' . $remainingDays . ' يوم (الموعد الأصلي: ' . $scheduledCheckOut . ')',
                        'reference' => 'REFUND-RES-' . $reservation->id,
                    ]);
                }
            }

            $reservation->update($updateData);
        });

        $this->logStatusChange($reservation, 'checked_in', 'checked_out', $request->user()?->id);

        $whatsappEnabled = HotelSetting::first()->whatsapp_notifications_enabled ?? true;
        if ($whatsappEnabled) {
            foreach ($reservation->rooms as $room) {
                $this->whatsappService->notifyCleaningCheckout($room);
            }
        }

        return response()->json($reservation->fresh()->load(['customer', 'rooms', 'transactions']));
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        if (in_array($reservation->status, ['checked_in', 'checked_out'])) {
            return response()->json(['message' => 'Cannot cancel after check-in'], 422);
        }
        $fromStatus = $reservation->status;
        $reservation->update(['status' => 'cancelled']);
        $this->logStatusChange($reservation, $fromStatus, 'cancelled', $request->user()?->id);

        $whatsappEnabled = HotelSetting::first()->whatsapp_notifications_enabled ?? true;
        $whatsappResult = $whatsappEnabled
            ? $this->whatsappService->sendCancellationTemplate($reservation)
            : ['success' => false, 'error' => 'WhatsApp notifications are disabled', 'skipped' => true];

        $responseData = $reservation->load(['customer', 'rooms.type', 'payments']);
        $responseData->whatsapp_result = $whatsappResult;

        return response()->json($responseData);
    }

    public function extend(Request $request, Reservation $reservation)
    {
        if (in_array($reservation->status, ['cancelled', 'checked_out'])) {
            return response()->json(['message' => 'Cannot extend a cancelled or checked-out reservation'], 422);
        }

        $data = $request->validate([
            'check_out_date' => ['required', 'date', 'after:' . $reservation->check_out_date],
        ]);

        $newCheckOut = $data['check_out_date'];
        $oldCheckOut = $reservation->check_out_date;

        // Check availability for the extension period
        $reservation->load('rooms');
        foreach ($reservation->rooms as $room) {
            $overlap = Reservation::query()
                ->where('id', '<>', $reservation->id)
                ->whereNotIn('status', ['cancelled', 'checked_out'])
                ->whereHas('occupyingRooms', function ($q) use ($room, $oldCheckOut, $newCheckOut) {
                    $q->where('rooms.id', $room->id)
                        ->where(function ($p) use ($oldCheckOut, $newCheckOut) {
                            $p->where(function ($x) use ($oldCheckOut, $newCheckOut) {
                                $x->where('reservation_room.check_in_date', '<', $newCheckOut)
                                    ->where('reservation_room.check_out_date', '>', $oldCheckOut);
                            })->orWhere(function ($x) use ($oldCheckOut, $newCheckOut) {
                                $x->whereNull('reservation_room.check_in_date')
                                    ->whereNull('reservation_room.check_out_date')
                                    ->where('reservations.check_in_date', '<', $newCheckOut)
                                    ->where('reservations.check_out_date', '>', $oldCheckOut);
                            });
                        });
                })
                ->exists();

            if ($overlap) {
                return response()->json(['message' => "Room {$room->number} is not available for the extension period"], 422);
            }
        }

        // Calculate extra cost
        $oldDate = new \DateTime($oldCheckOut);
        $newDate = new \DateTime($newCheckOut);
        $interval = $oldDate->diff($newDate);
        $extraDays = $interval->days;

        if ($extraDays <= 0) {
            return response()->json(['message' => 'Extension must be at least one day'], 422);
        }

        // Extra nights are always priced at the room's CURRENT live rate, not the
        // frozen rate stored on the reservation at booking time — so if the room's
        // price changed since booking, the already-booked nights keep their original
        // frozen price while the new extension nights use today's price.
        $extraAmount = 0;
        $roomRates = [];
        foreach ($reservation->rooms as $room) {
            $basePrice = $room->priceFor($reservation->currency);
            $roomRates[$room->id] = $basePrice;
            $extraAmount += $extraDays * $basePrice;
        }

        DB::transaction(function () use ($reservation, $newCheckOut, $oldCheckOut, $extraAmount, $extraDays, $roomRates) {
            // Update reservation
            $reservation->check_out_date = $newCheckOut;
            $reservation->total_amount += $extraAmount;
            $reservation->save();

            // Update rooms pivot (check_out_date only — pivot.rate stays the original frozen rate)
            foreach ($reservation->rooms as $room) {
                $reservation->rooms()->updateExistingPivot($room->id, [
                    'check_out_date' => $newCheckOut
                ]);

                $roomExtraAmount = $extraDays * $roomRates[$room->id];
                if ($roomExtraAmount > 0) {
                    Transaction::create([
                        'customer_id' => $reservation->customer_id,
                        'reservation_id' => $reservation->id,
                        'room_id' => $room->id,
                        'type' => 'debit',
                        'operation_type' => 'extension',
                        'amount' => $roomExtraAmount,
                        'rate' => $roomRates[$room->id],
                        'nights' => $extraDays,
                        'check_in_date' => $oldCheckOut,
                        'check_out_date' => $newCheckOut,
                        'currency' => $reservation->currency,
                        'transaction_date' => now(),
                        'reference' => 'EXT-' . $reservation->id . '-ROOM-' . $room->id . '-' . time(),
                        'notes' => 'غرفة ' . $room->number,
                    ]);
                }
            }
        });

        return response()->json($reservation->fresh()->load(['customer', 'rooms', 'transactions']));
    }

    public function transferRoom(Request $request, Reservation $reservation)
    {
        if (in_array($reservation->status, ['cancelled', 'checked_out'])) {
            return response()->json(['message' => 'لا يمكن تحويل غرفة في حجز ملغي أو منتهٍ'], 422);
        }

        $data = $request->validate([
            'old_room_id' => ['required', 'exists:rooms,id'],
            'new_room_id' => ['required', 'exists:rooms,id', 'different:old_room_id'],
        ]);

        $oldRoom = $reservation->rooms()->with('type')->where('rooms.id', $data['old_room_id'])->first();
        if (!$oldRoom) {
            return response()->json(['message' => 'الغرفة القديمة غير موجودة في هذا الحجز'], 422);
        }

        // Includes rooms already transferred out of: returning to one is not supported
        if ($reservation->stayRooms()->where('rooms.id', $data['new_room_id'])->exists()) {
            return response()->json(['message' => 'الغرفة الجديدة موجودة بالفعل في هذا الحجز'], 422);
        }

        $ci = $oldRoom->pivot->check_in_date ?? $reservation->check_in_date;
        $co = $oldRoom->pivot->check_out_date ?? $reservation->check_out_date;
        $ci = $ci instanceof \Carbon\Carbon ? $ci->toDateString() : substr($ci, 0, 10);
        $co = $co instanceof \Carbon\Carbon ? $co->toDateString() : substr($co, 0, 10);

        // A guest already in-house moves from tonight on, and the old room keeps the
        // nights already spent. Otherwise the new room takes the whole stay.
        $today = now()->toDateString();
        $from  = $reservation->status === 'checked_in' && $today > $ci ? $today : $ci;

        if ($from >= $co) {
            return response()->json(['message' => 'لا توجد ليالٍ متبقية في هذه الغرفة لتحويلها'], 422);
        }

        // Check new room availability (exclude cancelled/checked_out reservations)
        $overlap = Reservation::query()
            ->where('id', '<>', $reservation->id)
            ->whereNotIn('status', ['cancelled', 'checked_out'])
            ->whereHas('occupyingRooms', function ($q) use ($data, $from, $co) {
                $q->where('rooms.id', $data['new_room_id'])
                    ->where(function ($p) use ($from, $co) {
                        $p->where(function ($x) use ($from, $co) {
                            $x->where('reservation_room.check_in_date', '<', $co)
                                ->where('reservation_room.check_out_date', '>', $from);
                        })->orWhere(function ($x) use ($from, $co) {
                            $x->whereNull('reservation_room.check_in_date')
                                ->whereNull('reservation_room.check_out_date')
                                ->where('reservations.check_in_date', '<', $co)
                                ->where('reservations.check_out_date', '>', $from);
                        });
                    });
            })
            ->exists();

        if ($overlap) {
            return response()->json(['message' => 'الغرفة الجديدة غير متاحة للفترة المحددة'], 422);
        }

        $newRoom = \App\Models\Room::with('type')->find($data['new_room_id']);

        DB::transaction(function () use ($reservation, $oldRoom, $newRoom, $ci, $co, $from) {
            $newRate = (float) $newRoom->priceFor($reservation->currency);
            $oldRate = (float) ($oldRoom->pivot->rate ?? $oldRoom->priceFor($reservation->currency));
            $nightsBetween = fn (string $start, string $end) => (new \DateTime($start))->diff(new \DateTime($end))->days;

            // Booking, extension and earlier transfer debits of the old room. Rows migrated
            // from the old per-reservation format have no room_id, only the per-room reference.
            $segments = Transaction::where('reservation_id', $reservation->id)
                ->where('type', 'debit')
                ->whereNull('reservation_service_id')
                ->where(function ($q) use ($reservation, $oldRoom) {
                    $q->where('room_id', $oldRoom->id)
                        ->orWhere(function ($legacy) use ($reservation, $oldRoom) {
                            $legacy->whereNull('room_id')
                                ->where('reference', 'RES-' . $reservation->id . '-ROOM-' . $oldRoom->id);
                        });
                })
                ->get();

            // Price difference of the coming nights, each at the price it is charged at now
            $totalNights = $nightsBetween($from, $co);
            $coveredNights = 0;
            $difference = 0;
            foreach ($segments as $segment) {
                $segmentRate = (float) ($segment->rate ?? $oldRate);
                $segmentIn   = $segment->check_in_date?->toDateString() ?? $ci;
                $segmentOut  = $segment->check_out_date?->toDateString();

                if (!$segmentOut) {
                    $legacyNights = $segment->nights
                        ?? ($segmentRate > 0 ? (int) round($segment->amount / $segmentRate) : $nightsBetween($segmentIn, $co));
                    $segmentOut = (new \DateTime($segmentIn))->modify("+{$legacyNights} days")->format('Y-m-d');
                }

                if ($segmentOut <= $from) {
                    continue;
                }

                $movedNights = $nightsBetween(max($segmentIn, $from), $segmentOut);
                $coveredNights += $movedNights;
                $difference += round($movedNights * ($newRate - $segmentRate), 2);
            }

            // Nights with no charge row of their own, e.g. a reservation without per-room charges
            $difference += round(max(0, $totalNights - $coveredNights) * ($newRate - $oldRate), 2);

            // The old room's row stays on the reservation, ending at the transfer date
            $reservation->rooms()->updateExistingPivot($oldRoom->id, [
                'check_out_date'         => $from,
                'transferred_at'         => now(),
                'transferred_to_room_id' => $newRoom->id,
            ]);

            $reservation->rooms()->attach($newRoom->id, [
                'check_in_date'  => $from,
                'check_out_date' => $co,
                'rate'           => $newRate,
                'currency'       => $reservation->currency,
            ]);

            // The old charges stay as they are. Every transfer adds a debit with the difference,
            // even a zero one, so it shows on the statement. A cheaper room gives a negative
            // debit: a credit would count as money received.
            Transaction::create([
                'customer_id'      => $reservation->customer_id,
                'reservation_id'   => $reservation->id,
                'room_id'          => $newRoom->id,
                'type'             => 'debit',
                'operation_type'   => 'transfer',
                'amount'           => $difference,
                'rate'             => $newRate,
                'nights'           => $totalNights,
                'check_in_date'    => $from,
                'check_out_date'   => $co,
                'currency'         => $reservation->currency,
                'transaction_date' => $from,
                'reference'        => 'TRF-' . $reservation->id . '-ROOM-' . $newRoom->id . '-' . time(),
                'notes'            => "تحويل من غرفة {$oldRoom->number} إلى غرفة {$newRoom->number}: {$totalNights} ليلة (فرق " . number_format($difference, 2) . ')',
            ]);

            // Apply the difference only: other rooms and extensions are left untouched
            $reservation->update(['total_amount' => (float) $reservation->total_amount + $difference]);
        });

        $this->logRoomTransfer($reservation, $oldRoom->number, $newRoom->number, $from, $request->user()?->id);

        $fresh = $reservation->fresh()->load(['customer', 'rooms.type', 'transactions']);
        $fresh->setAttribute('has_room_transfer', true);

        return response()->json($fresh);
    }

    /**
     * Export reservations to Excel
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $query = Reservation::with(['customer', 'rooms', 'transactions']);

        // Apply the same filters as index method
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->whereHas('customer', function ($customerQuery) use ($searchTerm) {
                    $customerQuery->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('phone', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%");
                })
                    ->orWhereHas('stayRooms', function ($roomQuery) use ($searchTerm) {
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

        if (
            $request->has('date_from') && !empty($request->date_from) &&
            $request->has('date_to') && !empty($request->date_to)
        ) {
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
            $rooms = $reservation->rooms->map(function ($room) {
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

        return new StreamedResponse(function () use ($writer) {
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
        $reservation->load(['customer', 'rooms.type', 'stayRooms', 'transactions']);

        // Build invoice line items from the reservation's priced debit segments
        // (original booking + any extensions), so a room whose price changed
        // mid-stay prints as separate lines at their own frozen rates instead of
        // one line recomputed from today's live room price.
        $totalAmount = 0;
        $roomDetails = [];

        $debitSegments = $reservation->transactions
            ->where('type', 'debit')
            ->whereNotNull('room_id')
            ->sortBy('check_in_date');

        if ($debitSegments->isNotEmpty()) {
            foreach ($debitSegments as $segment) {
                $room = $reservation->stayRooms->firstWhere('id', $segment->room_id);
                $nights = $segment->nights ?? 1;
                $rate = $segment->isTransferDifference()
                    ? $segment->amount / max(1, $nights)
                    : ($segment->rate ?? ($nights > 0 ? $segment->amount / $nights : $segment->amount));
                $segCheckIn = $segment->check_in_date
                    ? date('Y/m/d', strtotime($segment->check_in_date))
                    : date('Y/m/d', strtotime($reservation->check_in_date));
                $segCheckOut = $segment->check_out_date
                    ? date('Y/m/d', strtotime($segment->check_out_date))
                    : date('Y/m/d', strtotime($reservation->check_out_date));

                $totalAmount += (float) $segment->amount;
                $roomDetails[] = [
                    'room_number' => $room ? $room->number : ('#' . $segment->room_id),
                    'check_in' => $segCheckIn,
                    'check_out' => $segCheckOut,
                    'days' => $nights,
                    'price_per_day' => $rate,
                    'total' => (float) $segment->amount,
                ];
            }
        } else {
            // Legacy fallback for reservations created before per-room pricing was tracked
            $checkIn = new \DateTime($reservation->check_in_date);
            $checkOut = new \DateTime($reservation->check_out_date);
            $days = max(1, $checkIn->diff($checkOut)->days);
            $fallbackCheckIn = date('Y/m/d', strtotime($reservation->check_in_date));
            $fallbackCheckOut = date('Y/m/d', strtotime($reservation->check_out_date));

            foreach ($reservation->rooms as $room) {
                $basePrice = $room->priceFor($reservation->currency);
                $roomAmount = $days * $basePrice;
                $totalAmount += $roomAmount;
                $roomDetails[] = [
                    'room_number' => $room->number,
                    'check_in' => $fallbackCheckIn,
                    'check_out' => $fallbackCheckOut,
                    'days' => $days,
                    'price_per_day' => $basePrice,
                    'total' => $roomAmount
                ];
            }
        }

        return $this->renderReservationInvoicePdf(
            $reservation,
            $roomDetails,
            $totalAmount,
            'فاتورة رقم (' . $reservation->id . ')',
            date('d/m/Y', strtotime($reservation->check_in_date)),
            'invoice_reservation_' . $reservation->id . '_' . date('Y-m-d') . '.pdf'
        );
    }

    /**
     * Invoice for a single extension event only — just the extended nights and
     * their amount, not the reservation's full booking history. $transaction_id
     * identifies which extension (a reservation may have been extended more than
     * once); sibling rooms extended in the same batch (same date range) are
     * included so a multi-room extension prints as one invoice.
     */
    public function exportExtensionInvoicePdf(Request $request, Reservation $reservation): Response|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer'],
        ]);

        $reservation->load(['customer', 'rooms.type', 'stayRooms']);

        $anchorTx = Transaction::where('id', $data['transaction_id'])
            ->where('reservation_id', $reservation->id)
            ->where('type', 'debit')
            ->first();

        if (!$anchorTx) {
            return response()->json(['message' => 'معاملة التمديد غير موجودة'], 404);
        }

        $segments = Transaction::where('reservation_id', $reservation->id)
            ->where('type', 'debit')
            ->whereNotNull('room_id')
            ->where('check_in_date', $anchorTx->check_in_date)
            ->where('check_out_date', $anchorTx->check_out_date)
            ->get()
            ->reject(fn ($segment) => $segment->isTransferDifference());

        $totalAmount = 0;
        $roomDetails = [];

        foreach ($segments as $segment) {
            $room = $reservation->stayRooms->firstWhere('id', $segment->room_id);
            $nights = $segment->nights ?? 1;
            $rate = $segment->rate ?? ($nights > 0 ? $segment->amount / $nights : $segment->amount);

            $totalAmount += (float) $segment->amount;
            $roomDetails[] = [
                'room_number' => $room ? $room->number : ('#' . $segment->room_id),
                'check_in' => $segment->check_in_date ? date('Y/m/d', strtotime($segment->check_in_date)) : '-',
                'check_out' => $segment->check_out_date ? date('Y/m/d', strtotime($segment->check_out_date)) : '-',
                'days' => $nights,
                'price_per_day' => $rate,
                'total' => (float) $segment->amount,
            ];
        }

        return $this->renderReservationInvoicePdf(
            $reservation,
            $roomDetails,
            $totalAmount,
            'فاتورة تمديد رقم (' . $reservation->id . ')',
            $anchorTx->check_in_date ? date('d/m/Y', strtotime($anchorTx->check_in_date)) : date('d/m/Y'),
            'extension_invoice_reservation_' . $reservation->id . '_' . date('Y-m-d') . '.pdf'
        );
    }

    /**
     * Shared invoice PDF renderer used by both the full-reservation invoice and
     * the single-extension invoice — same visual template, different line items.
     */
    private function renderReservationInvoicePdf(
        Reservation $reservation,
        array $roomDetails,
        float $totalAmount,
        string $invoiceTitle,
        string $invoiceDate,
        string $filename
    ): Response {
        $guestName = $reservation->customer->name ?? '';

        // Create PDF with RTL support
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetAuthor('Hotel Management System');
        $pdf->SetTitle($invoiceTitle);
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
            $logoImagePath = storage_path('app/public/' . $settings->logo_path);
            if (!file_exists($logoImagePath)) {
                $logoImagePath = public_path('storage/' . $settings->logo_path);
            }
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

        // Hotel name (from settings), invoice title, date, guest name — matches
        // the printed-invoice template's title block.
        $hotelName = ($settings->official_name ?? null) ?: ($settings->trade_name ?? null);

        if ($hotelName) {
            $pdf->SetFont('arial', 'B', 16);
            $pdf->Cell(0, 9, $hotelName, 0, 1, 'C');
        }

        $pdf->SetFont('arial', 'B', 14);
        $pdf->Cell(0, 8, $invoiceTitle, 0, 1, 'C');

        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(0, 7, 'التاريخ: ' . $invoiceDate, 0, 1, 'R');

        $pdf->Ln(3);

        // Invoice Table Header — matches the printed-invoice layout: room number,
        // check-in/out dates, guest name, nights, nightly rate, then the total.
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);

        $colRoom = 18;
        $colCheckIn = 26;
        $colCheckOut = 26;
        $colGuest = 42;
        $colDays = 16;
        $colPrice = 26;
        $colTotal = 26;
        // Total: 18+26+26+42+16+26+26 = 180mm

        $pdf->Cell($colRoom, 8, 'رقم الشقة', 1, 0, 'C', true);
        $pdf->Cell($colCheckIn, 8, 'تاريخ الوصول', 1, 0, 'C', true);
        $pdf->Cell($colCheckOut, 8, 'تاريخ المغادرة', 1, 0, 'C', true);
        $pdf->Cell($colGuest, 8, 'اسم العميل', 1, 0, 'C', true);
        $pdf->Cell($colDays, 8, 'عدد الأيام', 1, 0, 'C', true);
        $pdf->Cell($colPrice, 8, 'سعر اليوم', 1, 0, 'C', true);
        $pdf->Cell($colTotal, 8, 'الإجمالي', 1, 1, 'C', true);

        // Invoice Items
        $pdf->SetFont('arial', '', 9);
        foreach ($roomDetails as $room) {
            $pdf->Cell($colRoom, 7, $room['room_number'], 1, 0, 'C');
            $pdf->Cell($colCheckIn, 7, $room['check_in'], 1, 0, 'C');
            $pdf->Cell($colCheckOut, 7, $room['check_out'], 1, 0, 'C');
            $pdf->Cell($colGuest, 7, $guestName, 1, 0, 'C');
            $pdf->Cell($colDays, 7, $room['days'], 1, 0, 'C');
            $pdf->Cell($colPrice, 7, number_format($room['price_per_day'], 0, '.', '.'), 1, 0, 'C');
            $pdf->Cell($colTotal, 7, number_format($room['total'], 0, '.', '.'), 1, 1, 'C');
        }

        // Total Row
        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        $totalColSpan = $colRoom + $colCheckIn + $colCheckOut + $colGuest + $colDays + $colPrice;
        $pdf->Cell($totalColSpan, 8, 'الجملة', 1, 0, 'R', true);
        $pdf->Cell($colTotal, 8, number_format($totalAmount, 0, '.', '.'), 1, 1, 'C', true);

        // Bank account info — a distinct highlighted box below the table (not
        // styled like the totals table, so it doesn't read as another line item).
        $hasBank1 = $settings && $settings->bank_account_number_1;
        $hasBank2 = $settings && $settings->bank_account_number_2;

        if ($hasBank1 || $hasBank2) {
            $pdf->Ln(6);

            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(255, 248, 225);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetDrawColor(210, 170, 70);

            if ($hasBank1) {
                $line = 'رقم الحساب: ' . $settings->bank_account_number_1;
                if ($settings->bank_account_name_1) {
                    $line .= '   -   الاسم: ' . $settings->bank_account_name_1;
                }
                if ($settings->bank_name_1) {
                    $line .= '   -   ' . $settings->bank_name_1;
                }
                $pdf->Cell(0, 8, $line, 1, 1, 'R', true);
            }
            if ($hasBank2) {
                $line = 'رقم الحساب: ' . $settings->bank_account_number_2;
                if ($settings->bank_account_name_2) {
                    $line .= '   -   الاسم: ' . $settings->bank_account_name_2;
                }
                if ($settings->bank_name_2) {
                    $line .= '   -   ' . $settings->bank_name_2;
                }
                $pdf->Cell(0, 8, $line, 1, 1, 'R', true);
            }

            $pdf->SetDrawColor(0, 0, 0);
        }

        $pdf->SetAutoPageBreak(false);

        // Pre-calculate footer height so stamps can be anchored just above it
        $footerHeightMM  = 0;
        $footerImagePath = null;
        if ($settings && $settings->footer_path) {
            $fPath = storage_path('app/public/' . $settings->footer_path);
            if (!file_exists($fPath)) $fPath = public_path('storage/' . $settings->footer_path);
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
            if (!file_exists($sPath)) $sPath = public_path('storage/' . $settings->stamp_path);
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
            if (!file_exists($ePath)) $ePath = public_path('storage/' . $settings->e_stamp_path);
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

        // Re-enable auto page break
        $pdf->SetAutoPageBreak(true, $bottomMargin);

        // Generate PDF and return as response
        return response($pdf->Output($filename, 'S'), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * Printable list of today's arrivals or departures for the front desk.
     */
    public function exportTodayPdf(string $type, \App\Services\ReservationReportPdfService $pdfService): Response
    {
        $report = $pdfService->generate($type);

        return response($report['content'], 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $report['filename'] . '"');
    }
}
