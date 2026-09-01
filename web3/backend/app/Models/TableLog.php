<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableLog extends Model
{
    protected $fillable = [
        'client_event_id', 'table_id', 'billing_session_id', 'event_type', 'title', 'details',
        'reason', 'actor_name', 'payload', 'occurred_at',
    ];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public function billingSession(): BelongsTo { return $this->belongsTo(BillingSession::class); }
}
