<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $inventory_id
 * @property string $type
 * @property numeric $quantity_change
 * @property numeric $quantity_before
 * @property numeric $quantity_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Inventory $inventory
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereInventoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereQuantityAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereQuantityBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereQuantityChange($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereReferenceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereReferenceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryHistory whereUserId($value)
 * @mixin \Eloquent
 */
class InventoryHistory extends Model
{
    protected $table = 'inventory_history';

    protected $fillable = [
        'inventory_id',
        'type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'quantity_change' => 'decimal:2',
        'quantity_before' => 'decimal:2',
        'quantity_after' => 'decimal:2',
    ];

    /**
     * Get the inventory item.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * Get the user who made the change.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
