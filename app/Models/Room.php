<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $number
 * @property int $floor_id
 * @property int $room_type_id
 * @property int $room_status_id
 * @property int $beds
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Floor $floor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Reservation> $reservations
 * @property-read int|null $reservations_count
 * @property-read \App\Models\RoomStatus $status
 * @property-read \App\Models\RoomType $type
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereBeds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereFloorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereRoomStatusId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereRoomTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Room whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Room extends Model
{
    protected $fillable = [
        'number',
        'floor_id',
        'room_type_id',
        'room_status_id',
        'beds',
        'notes',
    ];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(RoomStatus::class, 'room_status_id');
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class)
            ->withPivot(['check_in_date', 'check_out_date', 'rate', 'currency'])
            ->withTimestamps();
    }
}
