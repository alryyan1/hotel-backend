<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $national_id
 * @property string|null $address
 * @property string|null $date_of_birth
 * @property string|null $gender
 * @property string|null $document_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $document_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Reservation> $reservations
 * @property-read int|null $reservations_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDateOfBirth($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDocumentPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereGender($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereNationalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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
