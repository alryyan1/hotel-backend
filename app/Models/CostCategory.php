<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Cost> $costs
 * @property-read int|null $costs_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CostCategory whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class CostCategory extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * Get the costs for the category.
     */
    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class);
    }
}
