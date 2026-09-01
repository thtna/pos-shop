<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('table_logs', function (Blueprint $table) {
            $table->id();
            $table->string('client_event_id', 64)->nullable()->unique()->index(); // Idempotency key from offline POS
            $table->unsignedBigInteger('table_id')->index();
            $table->foreignId('billing_session_id')->nullable()->constrained('billing_sessions')->nullOnDelete();
            // open_table, add_item, cancel_item, temp_print, pause_service, resume_service, table_transfer...
            $table->string('event_type', 60)->index();
            $table->string('title', 160);
            $table->text('details')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('actor_name', 100)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['table_id', 'occurred_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('table_logs'); }
};
