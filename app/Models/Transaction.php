<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ReservationService;
use App\Models\Concerns\BelongsToShift;

class Transaction extends Model
{
    use BelongsToShift;

    protected $fillable = [
        'customer_id',
        'reservation_id',
        'reservation_service_id',
        'room_id',
        'type',
        'operation_type',
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

    /**
     * A room transfer's price difference: its amount is not nights x rate, and its
     * rate is the nightly price the guest pays from the transfer on.
     */
    public function isTransferDifference(): bool
    {
        return $this->operation_type === 'transfer'
            || (str_starts_with($this->reference ?? '', 'TRF-') && str_starts_with($this->notes ?? '', 'تحويل'));
    }

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


