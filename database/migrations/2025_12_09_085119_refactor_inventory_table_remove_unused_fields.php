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
        // Ensure inventory_categories table exists
        if (!Schema::hasTable('inventory_categories')) {
            Schema::create('inventory_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        // Modify inventory table
        Schema::table('inventory', function (Blueprint $table) {
            // Drop unused columns
            if (Schema::hasColumn('inventory', 'code')) {
                $table->dropColumn('code');
            }
            if (Schema::hasColumn('inventory', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::hasColumn('inventory', 'unit')) {
                $table->dropColumn('unit');
            }
            if (Schema::hasColumn('inventory', 'purchase_price')) {
                $table->dropColumn('purchase_price');
            }
            if (Schema::hasColumn('inventory', 'selling_price')) {
                $table->dropColumn('selling_price');
            }
            if (Schema::hasColumn('inventory', 'supplier')) {
                $table->dropColumn('supplier');
            }
            if (Schema::hasColumn('inventory', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('inventory', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('inventory', 'is_active')) {
                $table->dropColumn('is_active');
            }

            // Change category from string to foreign key
            if (Schema::hasColumn('inventory', 'category')) {
                // First, add the new category_id column
                $table->foreignId('category_id')->nullable()->after('name')->constrained('inventory_categories')->onDelete('set null');
                
                // Note: We can't migrate existing category string values automatically
                // You may need to manually migrate data if needed
                
                // Drop the old category column
                $table->dropColumn('category');
            } elseif (!Schema::hasColumn('inventory', 'category_id')) {
                // If category column doesn't exist, just add category_id
                $table->foreignId('category_id')->nullable()->after('name')->constrained('inventory_categories')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            // Restore columns (simplified - you may need to adjust)
            if (!Schema::hasColumn('inventory', 'category')) {
                $table->string('category')->nullable()->after('name');
            }
            if (Schema::hasColumn('inventory', 'category_id')) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            }
            
            // Restore other columns if needed
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('unit')->default('قطعة');
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2)->nullable();
            $table->string('supplier')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }
};
