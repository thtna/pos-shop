<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemBomSnapshot extends Model
{
    protected $fillable = ['order_item_id', 'material_id', 'material_name', 'quantity_used', 'cost_price'];
    protected $casts = ['quantity_used' => 'decimal:6', 'cost_price' => 'decimal:2'];

    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
}
