<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early checkout no longer records a refund: the unused nights' value is kept on the
 * reservation only, so the ledger can show a "please refund" hint without any entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedInteger('early_checkout_nights')->nullable()->after('check_out_date');
            $table->decimal('early_checkout_refund', 12, 2)->nullable()->after('early_checkout_nights');
        });

        // Carry existing early-checkout refunds over to the hint, then drop them.
        $refunds = DB::table('transactions')->where('type', 'refund')->whereNotNull('reservation_id')->get();
        foreach ($refunds->groupBy('reservation_id') as $reservationId => $rows) {
            $nights = $rows->sum(fn ($r) => preg_match('/استرجاع\s+(\d+)\s+يوم/u', (string) $r->notes, $m) ? (int) $m[1] : 0);
            DB::table('reservations')->where('id', $reservationId)->update([
                'early_checkout_nights' => $nights ?: null,
                'early_checkout_refund' => $rows->sum('amount'),
            ]);
        }

        DB::table('transactions')->where('type', 'refund')->delete();
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('debit', 'credit') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('debit', 'credit', 'refund') NOT NULL");

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['early_checkout_nights', 'early_checkout_refund']);
        });
    }
};
