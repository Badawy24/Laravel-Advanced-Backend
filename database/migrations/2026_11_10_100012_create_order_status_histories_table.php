<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->enum('from_status', ['pending', 'confirmed', 'processing', 'completed', 'cancelled'])->nullable();
            $table->enum('to_status', ['pending', 'confirmed', 'processing', 'completed', 'cancelled']);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        DB::statement('ALTER TABLE order_status_histories ADD CONSTRAINT chk_order_status_histories_transition CHECK (from_status IS NULL OR from_status <> to_status)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};

