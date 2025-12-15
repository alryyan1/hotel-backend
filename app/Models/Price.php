<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $room_type_id
 * @property string $start_date
 * @property string|null $end_date
 * @property numeric $amount
 * @property string $currency
 * @property int $is_weekend
 * @property int $is_holiday
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\RoomType $roomType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereEndDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereIsHoliday($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereIsWeekend($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereRoomTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereStartDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Price whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Price extends Model
{
    protected $fillable = [
        'room_type_id',
        'start_date',
        'end_date',
        'amount',
        'currency',
        'is_weekend',
        'is_holiday',
        'notes',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
