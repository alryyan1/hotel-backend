<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // Fixes which of the room's two prices (price_usd/price_sdg) was used
            // to bill the whole reservation, so a bill never mixes currencies.
            $table->string('currency', 3)->default('SDG')->after('check_out_date');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
