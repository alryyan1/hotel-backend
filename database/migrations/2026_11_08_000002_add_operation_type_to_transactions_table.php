<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // What a debit row adjusts on its reservation: 'extension' or 'transfer'.
            // Null for the original booking charge and every non-adjustment row.
            $table->string('operation_type', 20)->nullable()->after('type');
        });

        // Backfill from the reference prefixes used before this column existed
        DB::table('transactions')->where('type', 'debit')->where('reference', 'like', 'EXT-%')
            ->update(['operation_type' => 'extension']);
        DB::table('transactions')->where('type', 'debit')->where('reference', 'like', 'TRF-%')
            ->where('notes', 'like', 'تحويل%')
            ->update(['operation_type' => 'transfer']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('operation_type');
        });
    }
};
