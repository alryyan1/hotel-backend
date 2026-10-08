<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
        'totals',
        'status',
        'open_flag',
        'notes',
    ];

    protected $hidden = ['open_flag'];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'totals' => 'array',
    ];

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class);
    }

    public function reservationServices(): HasMany
    {
        return $this->hasMany(ReservationService::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public static function current(): ?self
    {
        return static::where('status', 'open')->latest('id')->first();
    }
}
