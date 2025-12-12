<?php

namespace App\Console\Commands;

use App\Models\CleaningNotification;
use App\Models\Room;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CheckCleaningNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleaning:check-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for rooms that need cleaning notifications (every 2 days for occupied rooms)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for cleaning notifications...');

        // Get all rooms with their active reservations
        $rooms = Room::with(['reservations' => function($query) {
            $query->where('reservations.status', 'checked_in')
                ->where('reservations.check_in_date', '<=', now()->toDateString())
                ->where('reservations.check_out_date', '>=', now()->toDateString());
        }])->get();

        $notificationsCreated = 0;

        foreach ($rooms as $room) {
            // Get the current active reservation for this room
            $activeReservation = $room->reservations->first();
            
            // Skip if room doesn't have an active reservation
            if (!$activeReservation) {
                continue;
            }

            // Calculate days since check-in
            $checkInDate = Carbon::parse($activeReservation->check_in_date);
            $daysSinceCheckIn = $checkInDate->diffInDays(now());

            // Check if it's been 2 days or more, and it's a multiple of 2 days (every 2 days)
            if ($daysSinceCheckIn >= 2 && $daysSinceCheckIn % 2 == 0) {
                // Check if we already have a pending periodic notification for this room created today
                $existingNotification = CleaningNotification::where('room_id', $room->id)
                    ->where('type', 'periodic')
                    ->where('status', 'pending')
                    ->whereDate('created_at', today())
                    ->first();

                if (!$existingNotification) {
                    // Create periodic cleaning notification
                    CleaningNotification::create([
                        'room_id' => $room->id,
                        'reservation_id' => $activeReservation->id,
                        'type' => 'periodic',
                        'status' => 'pending',
                        'notified_at' => now(),
                    ]);

                    $notificationsCreated++;
                    $this->info("Created periodic cleaning notification for Room #{$room->number} (occupied for {$daysSinceCheckIn} days)");
                }
            }
        }

        $this->info("Created {$notificationsCreated} cleaning notification(s).");
        return 0;
    }
}
