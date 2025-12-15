<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $check_in_date
 * @property string $check_out_date
 * @property int $guest_count
 * @property string $status
 * @property numeric $total_amount
 * @property numeric $paid_amount
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereCheckInDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereCheckOutDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereGuestCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation wherePaidAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Reservation extends Model
{
    protected $fillable = [
        'customer_id',
        'check_in_date',
        'check_out_date',
        'guest_count',
        'status',
        'total_amount',
        'paid_amount',
        'notes',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)
            ->withPivot(['check_in_date', 'check_out_date', 'rate', 'currency'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
