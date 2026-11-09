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
        Schema::table('reservation_room', function (Blueprint $table) {
            $table->timestamp('transferred_at')->nullable()->after('currency');
            $table->foreignId('transferred_to_room_id')
                ->nullable()
                ->after('transferred_at')
                ->constrained('rooms')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_room', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferred_to_room_id');
            $table->dropColumn('transferred_at');
        });
    }
};
