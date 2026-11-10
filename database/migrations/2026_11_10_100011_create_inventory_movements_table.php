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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_inventory_id')->constrained('branch_inventories')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('reason', ['restock', 'reserve', 'release', 'expire', 'sale', 'adjustment']);
            $table->integer('quantity_delta')->default(0);
            $table->integer('reserved_delta')->default(0);
            $table->unsignedInteger('quantity_after');
            $table->unsignedInteger('reserved_after');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['reference_type', 'reference_id', 'branch_inventory_id', 'reason'],
                'inv_movements_ref_bi_reason_unique'
            );
            $table->index(['branch_inventory_id', 'created_at']);
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT chk_inv_movements_delta CHECK (quantity_delta <> 0 OR reserved_delta <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

