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
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('floor_id')->constrained('floors')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('room_status_id')->constrained('room_statuses')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedTinyInteger('beds')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
