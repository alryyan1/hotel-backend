<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateReservationTransactions extends Command
{
    protected $signature   = 'reservations:migrate-transactions';
    protected $description = 'Convert single-transaction reservations to per-room transactions';

    public function handle(): int
    {
        $reservationIds = Transaction::where('type', 'debit')
            ->whereRaw("reference REGEXP '^RES-[0-9]+$'")
            ->pluck('reservation_id')
            ->unique()
            ->filter();

        if ($reservationIds->isEmpty()) {
            $this->info('No legacy transactions found.');
            return 0;
        }

        $this->info("Found {$reservationIds->count()} reservation(s) to migrate.");
        $bar = $this->output->createProgressBar($reservationIds->count());
        $bar->start();

        $roomTypes = RoomType::all()->keyBy('id');
        $migrated  = 0;
        $skipped   = 0;

        foreach ($reservationIds as $reservationId) {
            try {
                DB::transaction(function () use ($reservationId, $roomTypes, &$migrated, &$skipped) {
                    $reservation = Reservation::with('rooms.type')->find($reservationId);

                    if (! $reservation || $reservation->rooms->isEmpty()) {
                        $skipped++;
                        return;
                    }

                    $oldTransaction = Transaction::where('reservation_id', $reservationId)
                        ->where('type', 'debit')
                        ->whereRaw("reference REGEXP '^RES-[0-9]+$'")
                        ->first();

                    if (! $oldTransaction) {
                        $skipped++;
                        return;
                    }

                    $checkIn  = new \DateTime($reservation->check_in_date);
                    $checkOut = new \DateTime($reservation->check_out_date);
                    $days     = max(1, $checkIn->diff($checkOut)->days);

                    $totalAmount = 0;

                    foreach ($reservation->rooms as $room) {
                        $basePrice = $room->pivot->rate ?? (
                            ($room->type && $room->type->base_price)
                            ? $room->type->base_price
                            : ($roomTypes[$room->room_type_id]->base_price ?? 0)
                        );
                        $roomAmount   = $days * (float) $basePrice;
                        $totalAmount += $roomAmount;

                        Transaction::create([
                            'customer_id'      => $reservation->customer_id,
                            'reservation_id'   => $reservation->id,
                            'type'             => 'debit',
                            'amount'           => $roomAmount,
                            'currency'         => $oldTransaction->currency ?? 'SDG',
                            'transaction_date' => $oldTransaction->transaction_date,
                            'reference'        => 'RES-' . $reservation->id . '-ROOM-' . $room->id,
                            'notes'            => 'غرفة ' . $room->number,
                        ]);
                    }

                    $oldTransaction->delete();
                    $reservation->update(['total_amount' => $totalAmount]);
                    $migrated++;
                });
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed reservation #{$reservationId}: " . $e->getMessage());
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Migrated: {$migrated} | Skipped: {$skipped}");

        return 0;
    }
}
