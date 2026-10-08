<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Transaction;
use App\Models\Concerns\BelongsToShift;

class ReservationService extends Model
{
    use BelongsToShift;

    protected $fillable = ['reservation_id', 'room_id', 'service_id', 'amount', 'payment_method', 'notes'];

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
