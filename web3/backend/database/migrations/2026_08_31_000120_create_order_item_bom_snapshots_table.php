<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_item_bom_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            // Giữ được lịch sử ngay cả khi nguyên liệu sau này bị xóa.
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('material_name', 160);
            // Số đã quy đổi theo đơn vị kho tại thời điểm bán.
            $table->decimal('quantity_used', 18, 6);
            // Giá vốn cho 1 đơn vị kho tại thời điểm bán.
            $table->decimal('cost_price', 18, 2);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('order_item_bom_snapshots'); }
};
