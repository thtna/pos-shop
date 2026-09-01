<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('offline_id', 64)->nullable()->unique()->index();
            $table->string('code', 32)->nullable()->index(); // PT-10001, PC-10002
            $table->enum('type', ['thu', 'chi'])->index(); // thu: Thu tiền, chi: Chi tiền
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('category_name', 150)->index(); // Chi nhập hàng, Chi điện nước, Nhập tiền lẻ...
            $table->string('payment_method', 50)->default('cash'); // cash (Tiền mặt), transfer (Chuyển khoản)
            $table->text('note')->nullable(); // Ghi chú chi tiết
            $table->string('user_name', 100)->nullable(); // Người lập phiếu (Admin, Thu ngân...)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
