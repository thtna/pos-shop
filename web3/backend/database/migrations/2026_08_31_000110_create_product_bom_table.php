<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_bom', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            // Lượng dùng cho 1 sản phẩm, luôn theo materials.recipe_unit.
            $table->decimal('quantity_per_unit', 18, 6);
            $table->timestamps();
            $table->unique(['product_id', 'material_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('product_bom'); }
};
