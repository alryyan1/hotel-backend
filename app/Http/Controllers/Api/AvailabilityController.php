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
                $q->whereNotIn('status', ['checked_out', 'cancelled'])
                  ->with('customer:id,name')
                  ->orderBy('check_out_date', 'desc');
            }])
            ->when(isset($data['room_type_id']), fn($q) => $q->where('room_type_id', $data['room_type_id']));

        $rooms = $query->get();

        // Transform rooms to include occupancy info and pricing
        $transformedRooms = $rooms->map(function ($room) use ($checkIn, $checkOut, $days) {
            // Find current active reservation
            $currentReservation = $room->reservations
                ->whereNotIn('status', ['checked_out', 'cancelled'])
                ->first();

            $isOccupied = $currentReservation !== null;

            // No currency is chosen yet at search time, so surface both rates
            // and let the reservation-creation step pick one for the whole bill.
            $priceUsd = $room->price_usd ?? 0;
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
                'total_price_usd' => $priceUsd * $days,
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
