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
        // Ensure inventory_categories table exists first
        if (!Schema::hasTable('inventory_categories')) {
            Schema::create('inventory_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // اسم العنصر
            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->onDelete('set null'); // الفئة
            $table->decimal('quantity', 10, 2)->default(0); // الكمية الحالية
            $table->decimal('minimum_stock', 10, 2)->default(0); // الحد الأدنى للمخزون
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
