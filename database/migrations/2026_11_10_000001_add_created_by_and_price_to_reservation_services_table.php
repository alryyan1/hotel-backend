<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_services', function (Blueprint $table) {
            // user_id is the user who settled the request; created_by is who entered it.
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            // Unit price quoted when the request is entered (the paid amount stays in amount/amount_usd).
            $table->decimal('price', 12, 2)->nullable()->after('quantity');
            $table->string('price_currency', 3)->nullable()->after('price');
        });

        // Before settling was a separate step, the entering user was also the payer.
        DB::table('reservation_services')->whereNull('created_by')->update(['created_by' => DB::raw('user_id')]);
    }

    public function down(): void
    {
        Schema::table('reservation_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['price', 'price_currency']);
        });
    }
};
