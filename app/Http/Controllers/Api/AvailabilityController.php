<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AvailabilityController extends Controller
{
    public function search(Request $request)
    {
        $data = $request->validate([
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'room_type_id' => ['nullable', 'exists:room_types,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
        ]);

        $checkIn = Carbon::parse($data['check_in_date']);
        $checkOut = Carbon::parse($data['check_out_date']);
        $days = max(1, $checkIn->diffInDays($checkOut));

        $query = Room::query()
            ->with(['type', 'status', 'floor', 'reservations' => function ($q) {
                $q->wherePivotNull('transferred_at')
                  ->whereNotIn('status', ['checked_out', 'cancelled'])
                  ->with('customer:id,name')
                  ->orderBy('check_out_date', 'desc');
            }])
            ->when(isset($data['room_type_id']), fn($q) => $q->where('room_type_id', $data['room_type_id']));

        $rooms = $query->get();

        // Transform rooms to include occupancy info and pricing
        $today = Carbon::today();
        $transformedRooms = $rooms->map(function ($room) use ($checkIn, $checkOut, $days, $today) {
            // "Occupied" means a reservation covers today, not just that one exists somewhere
            // in the future (e.g. a booking starting in 7 days must not block the room today).
            $currentReservation = $room->reservations
                ->whereNotIn('status', ['checked_out', 'cancelled'])
                ->first(function ($reservation) use ($today) {
                    return $today->gte(Carbon::parse($reservation->check_in_date)->startOfDay())
                        && $today->lt(Carbon::parse($reservation->check_out_date)->startOfDay());
                });

            $isOccupied = $currentReservation !== null;

            // No currency is chosen yet at search time, so surface both rates
            // and let the reservation-creation step pick one for the whole bill.
            // A room may have no USD rate at all (SDG-only); keep that as null so
            // the client can tell "no USD price" apart from a genuine 0.
            $priceUsd = $room->price_usd;
            $priceSdg = $room->price_sdg ?? 0;

            $roomData = [
                'id' => $room->id,
                'number' => $room->number,
                'type' => $room->type,
                'floor' => $room->floor,
                'status' => $room->status,
                'is_occupied' => $isOccupied,
                'price_usd' => $priceUsd,
                'price_sdg' => $priceSdg,
                'total_price_usd' => $priceUsd === null ? null : $priceUsd * $days,
                'total_price_sdg' => $priceSdg * $days,
                'current_reservation' => null,
            ];

            if ($isOccupied && $currentReservation) {
                $roomData['current_reservation'] = [
                    'id' => $currentReservation->id,
                    'customer_name' => $currentReservation->customer->name ?? 'غير معروف',
                    'check_out_date' => $currentReservation->check_out_date,
                ];
            }

            return $roomData;
        });

        return response()->json([
            'data' => $transformedRooms,
            'total' => $transformedRooms->count(),
        ]);
    }
}
