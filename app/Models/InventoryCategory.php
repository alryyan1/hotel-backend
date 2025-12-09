<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * Get the inventory items for the category.
     */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(Inventory::class, 'category_id');
    }
}

