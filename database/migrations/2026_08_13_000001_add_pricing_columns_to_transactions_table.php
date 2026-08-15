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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('room_id')
                ->nullable()
                ->after('reservation_service_id')
                ->constrained('rooms')
                ->nullOnDelete();
            $table->decimal('rate', 10, 2)->nullable()->after('amount');
            $table->integer('nights')->nullable()->after('rate');
            $table->date('check_in_date')->nullable()->after('nights');
            $table->date('check_out_date')->nullable()->after('check_in_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn(['rate', 'nights', 'check_in_date', 'check_out_date']);
        });
    }
};
