<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingSession extends Model
{
    protected $fillable = [
        'table_id', 'order_id', 'status', 'opened_by', 'started_at', 'ended_at',
        'active_pause_started_at', 'paused_seconds', 'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'ended_at' => 'datetime',
        'active_pause_started_at' => 'datetime', 'paused_seconds' => 'integer', 'meta' => 'array',
    ];

    public function pauses(): HasMany { return $this->hasMany(BillingSessionPause::class); }
    public function logs(): HasMany { return $this->hasMany(TableLog::class); }

    /** Số giây thực thu tiền giờ, đã trừ toàn bộ đoạn Pause. */
    public function billableSeconds(?\DateTimeInterface $now = null): int
    {
        $end = $this->ended_at ?? ($now ?: now());
        $openPause = $this->active_pause_started_at ? $this->active_pause_started_at->diffInSeconds($end) : 0;
        return max(0, $this->started_at->diffInSeconds($end) - (int) $this->paused_seconds - $openPause);
    }

    /**
     * Tính tiền dịch vụ theo số giây thực chơi và đơn giá giờ.
     * Hỗ trợ các chế độ làm tròn: exact_seconds (mặc định), minute_round (làm tròn lên theo phút), block_15m, block_30m.
     */
    public function calculateServiceFee(float $ratePerHour, string $roundingMode = 'exact_seconds', int $minMinutes = 0): float
    {
        $seconds = $this->billableSeconds();
        if ($minMinutes > 0) {
            $seconds = max($seconds, $minMinutes * 60);
        }

        switch ($roundingMode) {
            case 'minute_round':
                $billableMinutes = (int) ceil($seconds / 60);
                return ceil($ratePerHour * $billableMinutes / 60);

            case 'block_15m':
                $blocks = (int) ceil($seconds / 900);
                return ceil($ratePerHour * ($blocks * 15) / 60);

            case 'block_30m':
                $blocks = (int) ceil($seconds / 1800);
                return ceil($ratePerHour * ($blocks * 30) / 60);

            case 'exact_seconds':
            default:
                return (float) ceil($ratePerHour * max(1, $seconds) / 3600);
        }
    }
}
