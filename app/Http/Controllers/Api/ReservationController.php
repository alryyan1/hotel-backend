<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $reservations = Reservation::with(['customer', 'rooms', 'payments'])->paginate(20);
        return response()->json($reservations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required','string','max:50','unique:reservations,code'],
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
                })
                ->exists();
            if ($overlap) {
                return response()->json(['message' => 'Room not available for selected period','room_id' => $roomReq['id']], 422);
            }
        }

        $reservation = Reservation::create([
            'code' => $data['code'],
            'customer_id' => $data['customer_id'],
            'check_in_date' => $data['check_in_date'],
            'check_out_date' => $data['check_out_date'],
            'guest_count' => $data['guest_count'] ?? 1,
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? null,
        ]);

        $syncData = collect($data['rooms'])->mapWithKeys(function ($room) {
            return [
                $room['id'] => [
                    'check_in_date' => $room['check_in_date'] ?? null,
                    'check_out_date' => $room['check_out_date'] ?? null,
                    'rate' => $room['rate'] ?? null,
                    'currency' => $room['currency'] ?? 'USD',
                ],
            ];
        })->toArray();

        $reservation->rooms()->sync($syncData);

        return response()->json($reservation->load(['customer','rooms','payments']), 201);
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
            'code' => ['sometimes','string','max:50', Rule::unique('reservations','code')->ignore($reservation->id)],
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
            $syncData = collect($data['rooms'])->mapWithKeys(function ($room) {
                return [
                    $room['id'] => [
                        'check_in_date' => $room['check_in_date'] ?? null,
                        'check_out_date' => $room['check_out_date'] ?? null,
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
        return response()->json($reservation);
    }

    public function checkIn(Reservation $reservation)
    {
        if (! in_array($reservation->status, ['confirmed'])) {
            return response()->json(['message' => 'Reservation must be confirmed to check in'], 422);
        }

        // Set rooms to occupied
        DB::transaction(function () use ($reservation) {
            foreach ($reservation->rooms as $room) {
                $room->update(['room_status_id' => \App\Models\RoomStatus::where('code','occupied')->value('id')]);
            }
            $reservation->update(['status' => 'checked_in']);
        });

        return response()->json($reservation->fresh()->load('rooms'));
    }

    public function checkOut(Reservation $reservation)
    {
        if ($reservation->status !== 'checked_in') {
            return response()->json(['message' => 'Reservation must be checked in to check out'], 422);
        }

        DB::transaction(function () use ($reservation) {
            // Set rooms to cleaning after checkout
            $cleaningId = \App\Models\RoomStatus::where('code','cleaning')->value('id');
            foreach ($reservation->rooms as $room) {
                $room->update(['room_status_id' => $cleaningId]);
            }
            $reservation->update(['status' => 'checked_out']);
        });

        return response()->json($reservation->fresh()->load('rooms'));
    }

    public function cancel(Reservation $reservation)
    {
        if (in_array($reservation->status, ['checked_in','checked_out'])) {
            return response()->json(['message' => 'Cannot cancel after check-in'], 422);
        }
        $reservation->update(['status' => 'cancelled']);
        return response()->json($reservation);
    }
}
