<?php

namespace App\Services;

use App\Models\ReservationService;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class FinancialSummaryService
{
    /**
     * Revenue / services totals with per-method breakdowns.
     *
     * SDG is the only currency with more than one payment method (cash, bankak,
     * Ocash, fawri) — USD reservations can only ever be paid in physical cash
     * (enforced in TransactionController::store), so USD revenue is reported as
     * one "cash received" total rather than a method breakdown. Reservation
     * services are split by their billed currency: SDG services feed the SDG
     * figures, USD services are totalled separately (total_service_revenue_usd).
     *
     * @param  array<string, mixed>  $scope  Extra where-clauses applied to every source table (e.g. shift_id, user_id)
     */
    public function summarize(?string $dateFrom = null, ?string $dateTo = null, array $scope = []): array
    {
        $transactionQuery = Transaction::query();
        $serviceQuery = ReservationService::query();

        // Optional attribution filter (e.g. ['shift_id' => 3, 'user_id' => 7])
        foreach ($scope as $column => $value) {
            $transactionQuery->where($column, $value);
            $serviceQuery->where($column, $value);
        }

        if ($dateFrom) {
            $transactionQuery->where('transaction_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $transactionQuery->where('transaction_date', '<=', $dateTo);
        }

        $sdgTransactions = $transactionQuery->clone()->where('currency', 'SDG');

        // Total revenue (SDG credit transactions)
        $totalRevenue = (float) $sdgTransactions->clone()
            ->where('type', 'credit')
            ->sum('amount');

        // Payment method breakdown for revenue
        $revenueByMethod = $sdgTransactions->clone()
            ->where('type', 'credit')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method => (float) $item->total];
            })
            ->toArray();

        // Total debits (SDG reservation transactions)
        $totalDebits = (float) $sdgTransactions->clone()
            ->where('type', 'debit')
            ->sum('amount');

        // USD only ever settles as cash, so a single received total is all that's meaningful.
        $totalRevenueUsdCash = (float) $transactionQuery->clone()
            ->where('currency', 'USD')
            ->where('type', 'credit')
            ->sum('amount');

        // Service revenue counts when a request is settled, not when it is taken.
        $serviceQuery->whereNotNull('paid_at');
        if ($dateFrom) {
            $serviceQuery->where('paid_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $serviceQuery->where('paid_at', '<=', $dateTo);
        }

        // Services billed in USD are reported apart, never summed with SDG.
        $totalServiceRevenueUsd = (float) $serviceQuery->clone()->where('currency', 'USD')->sum('amount_usd');
        $serviceQuery->where('currency', 'SDG');

        // Get service revenue breakdown by method
        $serviceRevenueByMethod = $serviceQuery->clone()
            ->select('payment_method', DB::raw('SUM(amount) as total'))
            ->groupBy('payment_method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->payment_method ?: 'cash' => (float) $item->total];
            })
            ->toArray();

        // Total service revenue
        $totalServiceRevenue = (float) $serviceQuery->sum('amount');

        // Net profit (SDG revenue + service revenue) — USD cash
        // received is reported separately since it can't be summed with SDG.
        $netProfit = $totalRevenue + $totalServiceRevenue;

        return [
            'total_revenue' => $totalRevenue,
            'total_revenue_usd_cash' => $totalRevenueUsdCash,
            'total_service_revenue' => $totalServiceRevenue,
            'total_service_revenue_usd' => $totalServiceRevenueUsd,
            'revenue_by_method' => $revenueByMethod,
            'services_by_method' => $serviceRevenueByMethod,
            'total_debits' => $totalDebits,
            'net_profit' => $netProfit,
        ];
    }
}
