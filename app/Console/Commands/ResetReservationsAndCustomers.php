<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipes all reservation/customer activity (reservations, their rooms/services,
 * payments, transactions, cleaning notifications, status logs, and customers)
 * while leaving rooms, room types, floors and every other configuration table
 * untouched. Used to reset the system to a fresh, pre-launch state.
 */
class ResetReservationsAndCustomers extends Command
{
    protected $signature = 'reservations:reset-fresh-start {--force : Skip the confirmation prompt}';

    protected $description = 'Delete all reservations, customers, and their related records (payments, transactions, services, notifications), keeping rooms data intact';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm(
            'This will permanently delete ALL reservations, customers, payments, transactions, '
            . 'reservation services, cleaning notifications and status logs. Rooms/room types/floors '
            . 'are kept. Continue?'
        )) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        DB::transaction(function () {
            // Children with restrictOnDelete on reservations/customers must go first.
            $transactions = DB::table('transactions')->delete();
            $payments = DB::table('payments')->delete();

            $reservationServices = DB::table('reservation_services')->delete();
            $cleaningNotifications = DB::table('cleaning_notifications')->delete();
            $statusLogs = DB::table('reservation_status_logs')->delete();
            $reservationRooms = DB::table('reservation_room')->delete();

            $reservations = DB::table('reservations')->delete();

            // Customer uses SoftDeletes; DB::table() bypasses that scope so this is a
            // real, permanent delete of every row (including already-trashed ones).
            $customers = DB::table('customers')->delete();

            $this->table(
                ['Table', 'Rows deleted'],
                [
                    ['transactions', $transactions],
                    ['payments', $payments],
                    ['reservation_services', $reservationServices],
                    ['cleaning_notifications', $cleaningNotifications],
                    ['reservation_status_logs', $statusLogs],
                    ['reservation_room', $reservationRooms],
                    ['reservations', $reservations],
                    ['customers', $customers],
                ]
            );
        });

        $this->info('Done. Rooms, room types, floors and other configuration data were left untouched.');

        return self::SUCCESS;
    }
}
