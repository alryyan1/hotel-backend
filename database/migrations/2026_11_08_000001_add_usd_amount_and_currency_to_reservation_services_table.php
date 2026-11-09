<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_services', function (Blueprint $table) {
            // `amount` stays the SDG price; `amount_usd` is the optional USD price.
            // `currency` records which of the two was actually billed (it follows
            // the reservation's currency, falling back to SDG without a USD price).
            $table->decimal('amount_usd', 10, 2)->nullable()->after('amount');
            $table->string('currency', 3)->default('SDG')->after('amount_usd');
        });
    }

    public function down(): void
    {
        Schema::table('reservation_services', function (Blueprint $table) {
            $table->dropColumn(['amount_usd', 'currency']);
        });
    }
};
