<?php

namespace App\Services;

use App\Models\ReservationService;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class FinancialSummaryService
{
    /**
     * Revenue / services / refunds totals with per-method breakdowns.
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

        // Total revenue (credit transactions)
        $totalRevenue = (float) $transactionQuery->clone()
            ->where('type', 'credit')
            ->sum('amount');

        // Payment method breakdown for revenue
        $revenueByMethod = $transactionQuery->clone()
            ->where('type', 'credit')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method => (float) $item->total];
            })
            ->toArray();

        // Total debits (reservation transactions)
        $totalDebits = (float) $transactionQuery->clone()
            ->where('type', 'debit')
            ->sum('amount');

        // Total refunds (early checkout refunds)
        $totalRefunds = (float) $transactionQuery->clone()
            ->where('type', 'refund')
            ->sum('amount');

        // Payment method breakdown for refunds
        $refundsByMethod = $transactionQuery->clone()
            ->where('type', 'refund')
            ->select('method', DB::raw('SUM(amount) as total'))
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->method ?: 'cash' => (float) $item->total];
            })
            ->toArray();

        // Total service revenue
        if ($dateFrom) {
            $serviceQuery->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $serviceQuery->where('created_at', '<=', $dateTo);
        }

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

        // Net profit (revenue + service revenue - refunds)
        $netProfit = $totalRevenue + $totalServiceRevenue - $totalRefunds;

        return [
            'total_revenue' => $totalRevenue,
            'total_service_revenue' => $totalServiceRevenue,
            'revenue_by_method' => $revenueByMethod,
            'services_by_method' => $serviceRevenueByMethod,
            'total_debits' => $totalDebits,
            'total_refunds' => $totalRefunds,
            'refunds_by_method' => $refundsByMethod,
            'net_profit' => $netProfit,
        ];
    }
}
