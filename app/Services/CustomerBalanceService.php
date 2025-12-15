<?php
namespace App\Services;
use App\Models\Customer;
use App\Models\RoomType;
use DateTime;

class CustomerBalanceService
{
    public function calculate(Customer $customer):array
    {
        $customer->load('reservations.rooms.type','payments');
        $roomTypes = RoomType::all()->keyBy('id');
        $totalDebit = 0;

        foreach($customer->reservations as $reservation){
            $checkIn = new DateTime($reservation->check_in_date);
            $checkOut = new DateTime($reservation->check_out_date);
            $interval = $checkIn->diff($checkOut);
            $days = max(1, $interval->days);
            foreach($reservation->rooms as $room){
                // Get base price: first try room->type->base_price, then fallback to roomTypes lookup
                $basePrice = ($room->type && $room->type->base_price)
                    ? $room->type->base_price
                    : ($roomTypes[$room->room_type_id]->base_price ?? 0);
                $totalDebit += $basePrice * $days;
            }
        }

        $totalCredit = $customer->payments->sum('amount');
        $balance = $totalDebit - $totalCredit;
        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balance' => $balance,
        ];

    }
}