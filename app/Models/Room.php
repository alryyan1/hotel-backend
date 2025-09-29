<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
