<?php

namespace App\Models\Concerns;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stamps new financial records with the authenticated user and the open shift,
 * so every create() call site is covered without passing these explicitly.
 * Unauthenticated creates (e.g. public bookings) are left unattributed.
 */
trait BelongsToShift
{
    public static function bootBelongsToShift(): void
    {
        static::creating(function ($model) {
            $userId = auth()->id();
            if (!$userId) {
                return;
            }

            $model->user_id ??= $userId;
            $model->shift_id ??= Shift::current()?->id;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
