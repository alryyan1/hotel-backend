<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_receipt_id
 * @property int $inventory_id
 * @property numeric $quantity_received
 * @property numeric|null $purchase_price
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory $inventory
 * @property-read \App\Models\InventoryReceipt $receipt
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereInventoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereInventoryReceiptId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem wherePurchasePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereQuantityReceived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceiptItem whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class InventoryReceiptItem extends Model
{
    protected $fillable = [
        'inventory_receipt_id',
        'inventory_id',
        'quantity_received',
        'purchase_price',
        'notes',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    /**
     * Get the receipt that owns the item.
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(InventoryReceipt::class, 'inventory_receipt_id');
    }

    /**
     * Get the inventory item.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
