<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $number
 * @property string|null $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Floor whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Floor extends Model
{
    protected $fillable = [
        'number',
        'name',
        'description',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
