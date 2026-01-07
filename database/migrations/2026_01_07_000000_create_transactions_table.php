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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('reservation_id')
                ->nullable()
                ->constrained('reservations')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('method', ['cash', 'bankak', 'Ocash', 'fawri'])->nullable();
            $table->string('reference')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->dateTime('transaction_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};


