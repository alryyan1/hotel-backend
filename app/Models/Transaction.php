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
        'type',
        'amount',
        'currency',
        'method',
        'reference',
        'notes',
        'transaction_date',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
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
}


