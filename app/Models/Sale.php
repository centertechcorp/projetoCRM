<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'concluded_at' => 'datetime',
            'products_amount' => 'decimal:2',
            'services_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'freight' => 'decimal:2',
            'gross' => 'decimal:2',
            'net' => 'decimal:2',
            'cost' => 'decimal:2',
            'installments' => 'integer',
            'gc_created_at' => 'datetime',
            'gc_modified_at' => 'datetime',
        ];
    }
}
