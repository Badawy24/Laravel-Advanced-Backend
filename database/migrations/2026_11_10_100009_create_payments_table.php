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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('EGP');
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'applied_at']);
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

