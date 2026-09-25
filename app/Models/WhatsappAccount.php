<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'provider_config'];

    protected function casts(): array
    {
        return [
            'provider_config' => 'encrypted:array',
            'active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
