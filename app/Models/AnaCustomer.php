<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnaCustomer extends Model
{
    protected $table = 'ana_customers';

    protected $guarded = [];

    protected $casts = [
        'registered_at' => 'datetime',
        'is_public_sector' => 'boolean',
        'is_approver' => 'boolean',
        'is_decision_maker' => 'boolean',
        'is_influencer' => 'boolean',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(AnaOrder::class, 'customer_id');
    }
}
