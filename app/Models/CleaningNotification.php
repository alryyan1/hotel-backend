<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CleaningNotification extends Model
{
    protected $fillable = [
        'room_id',
        'reservation_id',
        'type',
        'status',
        'notes',
        'notified_at',
        'completed_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function markAsCompleted(?string $notes = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'notes' => $notes ?? $this->notes,
        ]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }
}
