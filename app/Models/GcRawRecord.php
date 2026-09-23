<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GcRawRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'gc_modified_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }
}
