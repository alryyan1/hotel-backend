<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $color
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomStatus whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class RoomStatus extends Model
{
    protected $fillable = [
        'code',
        'name',
        'color',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
