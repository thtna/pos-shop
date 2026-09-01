<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBom extends Model
{
    protected $table = 'product_bom';
    protected $fillable = ['product_id', 'material_id', 'quantity_per_unit'];
    protected $casts = ['quantity_per_unit' => 'decimal:6'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
}
