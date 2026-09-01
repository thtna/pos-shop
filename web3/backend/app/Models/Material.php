<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $fillable = ['code', 'name', 'import_unit', 'storage_unit', 'recipe_unit', 'conversion_rate', 'avg_cost', 'current_stock'];
    protected $casts = ['conversion_rate' => 'decimal:6', 'avg_cost' => 'decimal:2', 'current_stock' => 'decimal:6'];

    public function productBomItems(): HasMany { return $this->hasMany(ProductBom::class); }
    public function products(): BelongsToMany { return $this->belongsToMany(Product::class, 'product_bom')->withPivot('quantity_per_unit')->withTimestamps(); }
    public function orderItemBomSnapshots(): HasMany { return $this->hasMany(OrderItemBomSnapshot::class); }
}
