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
        // costs has outbound FKs to users/shifts/cost_categories, so it must drop first.
        Schema::dropIfExists('costs');
        Schema::dropIfExists('cost_categories');
    }

    /**
     * Reverse the migrations.
     *
     * Recreates the final shape both tables had just before removal. The Costs
     * feature's data itself is not recoverable — this only restores the schema.
     */
    public function down(): void
    {
        Schema::create('cost_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('costs', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->string('category')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('cost_category_id')->nullable()->constrained('cost_categories')->nullOnDelete();
            $table->enum('payment_method', ['cash', 'bankak'])->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();
        });
    }
};
