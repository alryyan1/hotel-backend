<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $description
 * @property numeric $amount
 * @property string|null $payment_method
 * @property \Illuminate\Support\Carbon $date
 * @property int|null $cost_category_id
 * @property string|null $category
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\CostCategory|null $costCategory
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereCostCategoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cost whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Cost extends Model
{
    protected $fillable = [
        'description',
        'amount',
        'date',
        'category',
        'cost_category_id',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    /**
     * Get the cost category that owns the cost.
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }
}


