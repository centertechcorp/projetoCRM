<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'products' => 'array',
            'quoted_amount' => 'decimal:2',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'next_contact_at' => 'datetime',
            'archive_after' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappChat::class, 'whatsapp_chat_id');
    }

    public function attendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attended_by_user_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function followups(): HasMany
    {
        return $this->hasMany(LeadFollowup::class);
    }
}
