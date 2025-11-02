<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Add customer_id column
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('customers')->cascadeOnUpdate()->restrictOnDelete();
        });

        // Get the actual foreign key constraint name
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'payments' 
            AND COLUMN_NAME = 'reservation_id' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        // Drop foreign key constraint before modifying reservation_id if it exists
        if (!empty($foreignKeys)) {
            $constraintName = $foreignKeys[0]->CONSTRAINT_NAME;
            DB::statement("ALTER TABLE payments DROP FOREIGN KEY {$constraintName}");
        }
        
        // Make reservation_id nullable
        DB::statement('ALTER TABLE payments MODIFY reservation_id BIGINT UNSIGNED NULL');
        
        // Re-add foreign key constraint with nullable
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_reservation_id_foreign FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON UPDATE CASCADE ON DELETE RESTRICT');
        
        // Update method enum values
        DB::statement("ALTER TABLE payments MODIFY method ENUM('cash', 'bankak', 'Ocash', 'fawri') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop customer_id
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });

        // Drop and recreate reservation_id foreign key as NOT NULL
        DB::statement('ALTER TABLE payments DROP FOREIGN KEY payments_reservation_id_foreign');
        
        // Set NULL values to a default (e.g., first reservation or handle in application)
        // For now, we'll make it NOT NULL after ensuring no NULL values exist
        DB::statement('ALTER TABLE payments MODIFY reservation_id BIGINT UNSIGNED NOT NULL');
        
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_reservation_id_foreign FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON UPDATE CASCADE ON DELETE RESTRICT');
        
        // Restore old method enum
        DB::statement("ALTER TABLE payments MODIFY method ENUM('cash', 'card', 'transfer', 'online') NOT NULL");
    }
};
