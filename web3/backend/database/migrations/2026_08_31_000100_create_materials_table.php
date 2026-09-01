<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->string('import_unit', 40);
            $table->string('storage_unit', 40);
            $table->string('recipe_unit', 40);
            // 1 storage_unit = conversion_rate recipe_unit; ví dụ 1 kg = 1000 g.
            $table->decimal('conversion_rate', 18, 6)->default(1);
            $table->decimal('avg_cost', 18, 2)->default(0); // giá vốn cho 1 storage_unit
            $table->decimal('current_stock', 18, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('materials'); }
};
