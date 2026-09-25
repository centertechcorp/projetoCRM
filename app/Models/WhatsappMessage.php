<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappChat::class, 'whatsapp_chat_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(WhatsappMedia::class);
    }
}
