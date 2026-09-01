<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use HasFactory;

    protected $table = 'cash_transactions';

    protected $fillable = [
        'offline_id',
        'code',
        'type', // 'thu' hoặc 'chi'
        'amount',
        'category_name',
        'payment_method', // 'cash' hoặc 'transfer'
        'note',
        'user_name',
        'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeThu($query)
    {
        return $query->where('type', 'thu');
    }

    public function scopeChi($query)
    {
        return $query->where('type', 'chi');
    }

    public function scopeCash($query)
    {
        return $query->where('payment_method', 'cash');
    }
}
