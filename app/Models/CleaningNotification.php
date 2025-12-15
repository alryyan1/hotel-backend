<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $room_id
 * @property int|null $reservation_id
 * @property string $type
 * @property string $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $notified_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Reservation|null $reservation
 * @property-read \App\Models\Room $room
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereNotifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereReservationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereRoomId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CleaningNotification whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
