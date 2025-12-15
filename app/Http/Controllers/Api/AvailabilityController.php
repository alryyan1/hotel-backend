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

        $checkIn = $data['check_in_date'];
        $checkOut = $data['check_out_date'];

        $query = Room::query()
            ->with(['type', 'status', 'floor'])
            ->when(isset($data['room_type_id']), fn($q) => $q->where('room_type_id', $data['room_type_id']))
            ->when(isset($data['guest_count']), fn($q) => $q->whereHas('type', function ($t) use ($data) {
                $t->where('capacity', '>=', $data['guest_count']);
            }))
            // exclude rooms that are currently checked in (regardless of dates)
            ->whereDoesntHave('reservations', function ($r) {
                $r->where('reservations.status', '!=', 'checked_out');
            });
            // exclude rooms that have overlapping active reservations (pending, confirmed)
            // ->whereDoesntHave('reservations', function ($r) use ($checkIn, $checkOut) {
            //     $r->whereIn('reservations.status', ['pending', 'confirmed'])
            //         ->where(function ($rr) use ($checkIn, $checkOut) {
            //             $rr->where(function ($p) use ($checkIn, $checkOut) {
            //                 $p->where('reservation_room.check_in_date', '<', $checkOut)
            //                     ->where('reservation_room.check_out_date', '>', $checkIn);
            //             })->orWhere(function ($p) use ($checkIn, $checkOut) {
            //                 // fallback to reservation dates if pivot dates null
            //                 $p->whereNull('reservation_room.check_in_date')
            //                     ->whereNull('reservation_room.check_out_date')
            //                     ->where('reservations.check_in_date', '<', $checkOut)
            //                     ->where('reservations.check_out_date', '>', $checkIn);
            //             });
            //         });
            // });

        $rooms = $query->paginate(20);
        return response()->json($rooms);
    }
}
