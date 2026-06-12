<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnaClaritySnapshot extends Model
{
    protected $table = 'ana_clarity_snapshots';

    protected $guarded = [];

    protected $casts = [
        'captured_on' => 'date',
        'devices' => 'array',
        'top_pages' => 'array',
        'raw' => 'array',
    ];
}
