<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnaOrder extends Model
{
    protected $table = 'ana_orders';

    protected $guarded = [];

    protected $casts = [
        'ordered_at' => 'datetime',
        'approved_at' => 'datetime',
        'is_cancelled' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AnaCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AnaOrderItem::class, 'order_id');
    }

    public function scopeValid($query)
    {
        return $query->where('is_cancelled', false);
    }
}
