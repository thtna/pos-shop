<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_sessions', function (Blueprint $table) {
            $table->id();
            // Không tạo FK tới `tables`: một số POS cũ dùng table_id local/remote khác nhau.
            $table->unsignedBigInteger('table_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('status', 24)->default('open')->index(); // open, paused, paid, cancelled, merged
            $table->string('opened_by', 100)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamp('active_pause_started_at')->nullable();
            // Tổng các khoảng dừng đã đóng; dùng để tính tiền giờ chính xác ở backend.
            $table->unsignedBigInteger('paused_seconds')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['table_id', 'ended_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('billing_sessions'); }
};
