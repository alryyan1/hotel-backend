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
            $table->unsignedInteger('quantity')->default(1)->after('service_id');
            $table->timestamp('paid_at')->nullable()->after('payment_method');
        });

        // Services were paid on entry before the separate settle step existed.
        DB::table('reservation_services')
            ->where(fn ($q) => $q->where('amount', '>', 0)->orWhereNotNull('amount_usd'))
            ->update(['paid_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('reservation_services', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'paid_at']);
        });
    }
};
