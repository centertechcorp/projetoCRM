<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappGroupMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }
}
