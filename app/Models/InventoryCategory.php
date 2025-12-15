<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Inventory> $inventoryItems
 * @property-read int|null $inventory_items_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryCategory whereUpdatedAt($value)
 * @mixin \Eloquent
 */
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

