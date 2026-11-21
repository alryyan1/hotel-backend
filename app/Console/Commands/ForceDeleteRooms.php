<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes specific rooms together with everything that references
 * them (reservation_room rows, room services, their transactions, cleaning
 * notifications). Reservations themselves are kept, even if they end up with
 * no rooms left; those are listed at the end so they can be reviewed.
 *
 * Usage:
 *   php artisan rooms:force-delete 101 102 205        (by room number)
 *   php artisan rooms:force-delete 7 9 --id           (by room id)
 *   php artisan rooms:force-delete 101 --force        (skip confirmation)
 */
class ForceDeleteRooms extends Command
{
    protected $signature = 'rooms:force-delete
        {rooms* : Room numbers (or ids with --id)}
        {--id : Treat the given values as room ids instead of room numbers}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently delete specific rooms and all their related records, including transactions';

    public function handle(): int
    {
        $values = $this->argument('rooms');
        $column = $this->option('id') ? 'id' : 'number';

        $rooms = DB::table('rooms')->whereIn($column, $values)->get(['id', 'number']);

        $missing = array_diff($values, $rooms->pluck($column)->map(fn ($v) => (string) $v)->all());
        if ($missing) {
            $this->warn('Not found: ' . implode(', ', $missing));
        }
        if ($rooms->isEmpty()) {
            $this->error('No matching rooms.');
            return self::FAILURE;
        }

        $roomIds = $rooms->pluck('id')->all();
        $serviceIds = DB::table('reservation_services')->whereIn('room_id', $roomIds)->pluck('id')->all();
        $transactionsQuery = fn () => DB::table('transactions')
            ->where(fn ($q) => $q->whereIn('room_id', $roomIds)->orWhereIn('reservation_service_id', $serviceIds));
        $reservationIds = DB::table('reservation_room')->whereIn('room_id', $roomIds)
            ->distinct()->pluck('reservation_id')->all();

        $this->info('Rooms to delete: ' . $rooms->map(fn ($r) => "{$r->number} (id {$r->id})")->implode(', '));
        $this->table(['Related table', 'Rows'], [
            ['transactions', $transactionsQuery()->count()],
            ['reservation_services', count($serviceIds)],
            ['cleaning_notifications', DB::table('cleaning_notifications')->whereIn('room_id', $roomIds)->count()],
            ['reservation_room', DB::table('reservation_room')->whereIn('room_id', $roomIds)->count()],
            ['reservation_room (transferred_to, will be unlinked)', DB::table('reservation_room')->whereIn('transferred_to_room_id', $roomIds)->count()],
        ]);

        if (!$this->option('force') && !$this->confirm('This is permanent and cannot be undone. Continue?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($roomIds, $serviceIds, $transactionsQuery) {
            $transactionsQuery()->delete();
            DB::table('reservation_services')->whereIn('id', $serviceIds)->delete();
            DB::table('cleaning_notifications')->whereIn('room_id', $roomIds)->delete();
            DB::table('reservation_room')->whereIn('transferred_to_room_id', $roomIds)
                ->update(['transferred_to_room_id' => null]);
            DB::table('reservation_room')->whereIn('room_id', $roomIds)->delete();
            DB::table('rooms')->whereIn('id', $roomIds)->delete();
        });

        $this->info('Deleted ' . count($roomIds) . ' room(s).');

        $orphaned = DB::table('reservations')->whereIn('id', $reservationIds)
            ->whereNotExists(fn ($q) => $q->from('reservation_room')->whereColumn('reservation_room.reservation_id', 'reservations.id'))
            ->pluck('id');
        if ($orphaned->isNotEmpty()) {
            $this->warn('Reservations now without any room (kept, review manually): ' . $orphaned->implode(', '));
        }

        return self::SUCCESS;
    }
}
