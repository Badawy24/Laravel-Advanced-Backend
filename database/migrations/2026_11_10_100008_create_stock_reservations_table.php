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
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->restrictOnDelete();
            $table->foreignId('branch_inventory_id')->constrained('branch_inventories')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['active', 'released', 'expired', 'consumed'])->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['branch_inventory_id', 'status']);
        });

        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT chk_stock_reservations_quantity CHECK (quantity > 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT chk_stock_reservations_resolved_at CHECK ((status = 'active' AND resolved_at IS NULL) OR (status <> 'active' AND resolved_at IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};

