<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('billing_session_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_session_id')->constrained('billing_sessions')->cascadeOnDelete();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->string('paused_by', 100)->nullable();
            $table->string('resumed_by', 100)->nullable();
            $table->string('reason', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('billing_session_pauses'); }
};
