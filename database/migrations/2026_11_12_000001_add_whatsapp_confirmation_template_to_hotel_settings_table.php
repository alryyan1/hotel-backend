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
        Schema::table('hotel_settings', function (Blueprint $table) {
            // Name of the Meta-approved WhatsApp template sent on booking confirmation;
            // each branch can point at its own template. Null falls back to the default.
            $table->string('whatsapp_confirmation_template')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table) {
            $table->dropColumn('whatsapp_confirmation_template');
        });
    }
};
