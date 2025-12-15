<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_order_id
 * @property int $inventory_id
 * @property numeric $quantity
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory $inventory
 * @property-read \App\Models\InventoryOrder $order
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereInventoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereInventoryOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrderItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class InventoryOrderItem extends Model
{
    protected $fillable = [
        'inventory_order_id',
        'inventory_id',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * Get the order that owns the item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(InventoryOrder::class, 'inventory_order_id');
    }

    /**
     * Get the inventory item.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
