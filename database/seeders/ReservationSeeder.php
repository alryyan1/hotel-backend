<?php

namespace Database\Seeders;

use App\Models\Reservation;
use App\Models\Customer;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;
use Carbon\Carbon;

class ReservationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        // Get all customers and rooms
        $customers = Customer::all();
        $rooms = Room::all();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers found. Please run CustomerSeeder first.');
            return;
        }

        if ($rooms->isEmpty()) {
            $this->command->warn('No rooms found. Please ensure rooms are seeded first.');
            return;
        }

        $statuses = ['pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled'];

        for ($i = 0; $i < 50; $i++) {
            // Generate random check-in date (between 30 days ago and 30 days from now)
            $checkInDate = $faker->dateTimeBetween('-30 days', '+30 days');
            $checkIn = Carbon::parse($checkInDate);
            
            // Generate check-out date (1 to 7 days after check-in)
            $nights = $faker->numberBetween(1, 7);
            $checkOut = $checkIn->copy()->addDays($nights);

            // Calculate total amount (random between 100 and 2000)
            $totalAmount = $faker->randomFloat(2, 100, 2000);
            
            // Paid amount should be less than or equal to total amount
            $paidAmount = $faker->randomFloat(2, 0, $totalAmount);

            // Create reservation
            $reservation = Reservation::create([
                'customer_id' => $faker->randomElement($customers)->id,
                'check_in_date' => $checkIn->format('Y-m-d'),
                'check_out_date' => $checkOut->format('Y-m-d'),
                'guest_count' => $faker->numberBetween(1, 4),
                'status' => $faker->randomElement($statuses),
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'notes' => $faker->optional(0.3)->sentence(), // 30% chance of having notes
            ]);

            // Attach 1-2 random rooms to the reservation
            $roomsToAttach = $rooms->random($faker->numberBetween(1, min(2, $rooms->count())));
            
            foreach ($roomsToAttach as $room) {
                // Get room type base price or use a default
                $roomType = $room->type;
                $rate = $roomType ? $roomType->base_price : $faker->randomFloat(2, 100, 500);
                
                $reservation->rooms()->attach($room->id, [
                    'check_in_date' => $checkIn->format('Y-m-d'),
                    'check_out_date' => $checkOut->format('Y-m-d'),
                    'rate' => $rate,
                    'currency' => 'USD', // or whatever currency you use
                ]);
            }
        }
    }
}




