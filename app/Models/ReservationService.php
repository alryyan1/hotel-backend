<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Transaction;
use App\Models\Concerns\BelongsToShift;

class ReservationService extends Model
{
    use BelongsToShift;

    protected $fillable = ['reservation_id', 'room_id', 'service_id', 'amount', 'amount_usd', 'currency', 'payment_method', 'notes'];

    protected $appends = ['billed_amount'];

    /**
     * Bill in the reservation's currency, but only USD when a USD price was entered.
     */
    public static function currencyFor(?Reservation $reservation, $amountUsd): string
    {
        return ($reservation?->currency === 'USD' && $amountUsd !== null) ? 'USD' : 'SDG';
    }

    /**
     * The amount actually charged, in `currency`.
     */
    public function getBilledAmountAttribute(): float
    {
        return (float) ($this->currency === 'USD' ? $this->amount_usd : $this->amount);
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
