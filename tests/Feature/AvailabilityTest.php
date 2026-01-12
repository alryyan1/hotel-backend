<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_check_excludes_cancelled_reservations()
    {
        // 1. Setup Data
        $user = User::factory()->create();
        $roomType = RoomType::factory()->create();
        $room = Room::factory()->create([
            'room_type_id' => $roomType->id,
            'number' => '101'
        ]);
        $customer = Customer::factory()->create();

        $checkIn = now()->format('Y-m-d');
        $checkOut = now()->addDays(2)->format('Y-m-d');

        // 2. Create a CANCELLED reservation for this period
        $cancelledReservation = Reservation::create([
            'customer_id' => $customer->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => 'cancelled',
            'guest_count' => 1
        ]);
        $cancelledReservation->rooms()->attach($room->id);

        // 3. Check Availability - Room should be AVAILABLE (is_occupied = false)
        $response = $this->actingAs($user)->getJson("/api/availability?check_in_date={$checkIn}&check_out_date={$checkOut}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Find our room in the response
        $roomData = collect($data)->firstWhere('id', $room->id);

        $this->assertNotNull($roomData, 'Room was not found in availability search');
        $this->assertFalse($roomData['is_occupied'], 'Room should be available (not occupied) with cancelled reservation');

        // 4. Create a CONFIRMED reservation for the same period
        $confirmedReservation = Reservation::create([
            'customer_id' => $customer->id,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'status' => 'confirmed',
            'guest_count' => 1
        ]);
        $confirmedReservation->rooms()->attach($room->id);

        // 5. Check Availability again - Room should be OCCUPIED
        $response = $this->actingAs($user)->getJson("/api/availability?check_in_date={$checkIn}&check_out_date={$checkOut}");

        $response->assertStatus(200);
        $data = $response->json('data');
        $roomData = collect($data)->firstWhere('id', $room->id);

        $this->assertTrue($roomData['is_occupied'], 'Room should be occupied with confirmed reservation');
        $this->assertEquals($confirmedReservation->id, $roomData['current_reservation']['id']);
    }
}
