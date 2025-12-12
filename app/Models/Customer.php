<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'national_id',
        'address',
        'date_of_birth',
        'gender',
        'document_path',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the document URL attribute.
     */
    public function getDocumentUrlAttribute(): ?string
    {
        if (!$this->document_path) {
            return null;
        }

        return Storage::disk('public')->url($this->document_path);
    }
}
