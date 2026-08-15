<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ReservationService;

class Transaction extends Model
{
    protected $fillable = [
        'customer_id',
        'reservation_id',
        'reservation_service_id',
        'room_id',
        'type',
        'amount',
        'rate',
        'nights',
        'check_in_date',
        'check_out_date',
        'currency',
        'method',
        'reference',
        'notes',
        'transaction_date',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'check_in_date' => 'date',
        'check_out_date' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reservationService(): BelongsTo
    {
        return $this->belongsTo(ReservationService::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}


