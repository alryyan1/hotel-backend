<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->json('totals')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            // true while open, NULL once closed: the unique index lets the database
            // itself guarantee only one shift is ever open (multiple NULLs are allowed)
            $table->boolean('open_flag')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
