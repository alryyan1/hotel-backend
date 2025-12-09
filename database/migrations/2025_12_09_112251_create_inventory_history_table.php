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
        Schema::create('inventory_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventory')->onDelete('cascade');
            $table->string('type'); // 'add', 'deduct', 'adjust'
            $table->decimal('quantity_change', 10, 2); // Positive for add, negative for deduct
            $table->decimal('quantity_before', 10, 2); // Quantity before change
            $table->decimal('quantity_after', 10, 2); // Quantity after change
            $table->string('reference_type')->nullable(); // 'receipt', 'order', 'manual'
            $table->unsignedBigInteger('reference_id')->nullable(); // ID of receipt, order, etc.
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_history');
    }
};
