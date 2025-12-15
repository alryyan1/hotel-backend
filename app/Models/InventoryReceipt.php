<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $receipt_number
 * @property \Illuminate\Support\Carbon $receipt_date
 * @property string|null $supplier
 * @property string|null $notes
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\InventoryReceiptItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereReceiptDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereReceiptNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereSupplier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InventoryReceipt whereUserId($value)
 * @mixin \Eloquent
 */
class InventoryReceipt extends Model
{
    protected $fillable = [
        'receipt_number',
        'receipt_date',
        'supplier',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    /**
     * Get the user that created the receipt.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the receipt items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryReceiptItem::class, 'inventory_receipt_id');
    }

    /**
     * Generate receipt number
     */
    public static function generateReceiptNumber(): string
    {
        $prefix = 'REC-';
        $date = date('Ymd');
        $lastReceipt = self::where('receipt_number', 'like', $prefix . $date . '%')
            ->orderBy('receipt_number', 'desc')
            ->first();
        
        if ($lastReceipt) {
            $lastNumber = (int) substr($lastReceipt->receipt_number, -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . $date . '-' . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
    }
}
