<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnaSnapshotRun extends Model
{
    protected $table = 'ana_snapshot_runs';

    protected $guarded = [];

    protected $casts = [
        'ran_at' => 'datetime',
    ];
}
