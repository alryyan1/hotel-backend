<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $order_number
 * @property \Illuminate\Support\Carbon $order_date
 * @property string $status
 * @property string|null $notes
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryOrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereOrderDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereOrderNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryOrder whereUserId($value)
 * @mixin \Eloquent
 */
class InventoryOrder extends Model
{
    protected $fillable = [
        'order_number',
        'order_date',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    /**
     * Get the user that created the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryOrderItem::class, 'inventory_order_id');
    }

    /**
     * Generate order number
     */
    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD-';
        $date = date('Ymd');
        $lastOrder = self::where('order_number', 'like', $prefix . $date . '%')
            ->orderBy('order_number', 'desc')
            ->first();
        
        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
