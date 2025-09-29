<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
