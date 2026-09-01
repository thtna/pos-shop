<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSessionPause extends Model
{
    protected $fillable = [
        'billing_session_id', 'started_at', 'ended_at', 'duration_seconds',
        'paused_by', 'resumed_by', 'reason',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'ended_at' => 'datetime', 'duration_seconds' => 'integer',
    ];

    public function session(): BelongsTo { return $this->belongsTo(BillingSession::class, 'billing_session_id'); }
}
