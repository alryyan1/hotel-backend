<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
