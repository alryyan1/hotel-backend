<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Transaction;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $check_in_date
 * @property string $check_out_date
 * @property string $currency
 * @property string $status
 * @property numeric $total_amount
 * @property numeric $paid_amount
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Reservation whereId($value)
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
        'currency',
        'status',
        'total_amount',
        'paid_amount',
        'checkout_reminder_sent_at',
    ];

    protected $casts = [
        'checkout_reminder_sent_at' => 'datetime',
    ];

    // Old rooms stay visible after a transfer, whichever endpoint returns the reservation
    protected $with = ['transferredRooms'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(ReservationStatusLog::class);
    }

    /**
     * The log entry recorded when this reservation was first created — used to show
     * who recorded the reservation ("سجل بواسطه") without a dedicated created_by column.
     */
    public function creationLog(): HasOne
    {
        return $this->hasOne(ReservationStatusLog::class)->whereNull('from_status')->oldest('id');
    }

    /**
     * The rooms the reservation holds now: rooms it was transferred out of are excluded.
     */
    public function rooms(): BelongsToMany
    {
        return $this->stayRooms()->wherePivotNull('transferred_at');
    }

    /**
     * Rooms the guest stayed in earlier, kept with the dates spent in each one.
     */
    public function transferredRooms(): BelongsToMany
    {
        return $this->stayRooms()->wherePivotNotNull('transferred_at');
    }

    /**
     * Rooms that block availability: a transferred room is still taken for the nights
     * spent in it, unless the guest moved out before spending any night there.
     */
    public function occupyingRooms(): BelongsToMany
    {
        return $this->stayRooms()->where(function ($q) {
            $q->whereNull('reservation_room.transferred_at')
                ->orWhereColumn('reservation_room.check_in_date', '<', 'reservation_room.check_out_date');
        });
    }

    /**
     * Every room of the stay, current and transferred.
     */
    public function stayRooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)
            ->withPivot(['check_in_date', 'check_out_date', 'rate', 'currency', 'transferred_at', 'transferred_to_room_id'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
