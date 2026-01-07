<?php
namespace App\Services;
use App\Models\Customer;

class CustomerBalanceService
{
    public function calculate(Customer $customer):array
    {
        $customer->load('transactions');
        
        $totalDebit = $customer->transactions()
            ->where('type', 'debit')
            ->sum('amount');
        
        $totalCredit = $customer->transactions()
            ->where('type', 'credit')
            ->sum('amount');
        
        $balance = $totalDebit - $totalCredit;
        
        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balance' => $balance,
        ];
    }
}