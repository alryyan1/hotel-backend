<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $capacity
 * @property int $base_price
 * @property string|null $description
 * @property int|null $area
 * @property int|null $beds_count
 * @property array<array-key, mixed>|null $amenities
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Price> $prices
 * @property-read int|null $prices_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Room> $rooms
 * @property-read int|null $rooms_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereAmenities($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereArea($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereBedsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereCapacity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoomType whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class RoomType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'capacity',
        'base_price',
        'description',
        'area',
        'beds_count',
        'amenities',
    ];

    protected $casts = [
        'amenities' => 'array',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
