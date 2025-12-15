<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property int|null $category_id
 * @property numeric $quantity
 * @property numeric $minimum_stock
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\InventoryCategory|null $category
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereMinimumStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Inventory whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'name',
        'category_id',
        'quantity',
        'minimum_stock',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'minimum_stock' => 'decimal:2',
    ];

    /**
     * Get the category that owns the inventory item.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    /**
     * Check if stock is low (below minimum)
     */
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->minimum_stock;
    }

    /**
     * Get stock status
     */
    public function getStockStatus(): string
    {
        if ($this->quantity <= 0) {
            return 'out_of_stock';
        } elseif ($this->isLowStock()) {
            return 'low_stock';
        }
        return 'in_stock';
    }
}

