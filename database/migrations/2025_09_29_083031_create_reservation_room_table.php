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
        Schema::create('reservation_room', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->decimal('rate', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unique(['reservation_id', 'room_id'], 'reservation_room_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_room');
    }
};
