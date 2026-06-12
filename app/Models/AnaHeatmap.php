<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnaHeatmap extends Model
{
    protected $table = 'ana_heatmaps';

    protected $guarded = [];

    protected $casts = [
        'csv_data' => 'array',
    ];
}
