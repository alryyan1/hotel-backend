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
        Schema::create('inventory_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique(); // رقم الطلب
            $table->date('order_date'); // تاريخ الطلب
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending'); // حالة الطلب
            $table->text('notes')->nullable(); // ملاحظات
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // المستخدم الذي أنشأ الطلب
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_orders');
    }
};
