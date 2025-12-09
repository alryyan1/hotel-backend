<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
