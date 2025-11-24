<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
