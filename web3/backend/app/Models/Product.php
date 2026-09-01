<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public function bomItems(): HasMany { return $this->hasMany(ProductBom::class); }
    public function materials(): BelongsToMany { return $this->belongsToMany(Material::class, 'product_bom')->withPivot('quantity_per_unit')->withTimestamps(); }
}
