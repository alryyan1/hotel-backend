<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
