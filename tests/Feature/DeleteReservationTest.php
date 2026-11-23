<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Floor;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\RoomType;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['is_admin' => true]);
        Shift::create(['opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'open_flag' => true]);
    }

    private function book(string $status = 'confirmed'): Reservation
    {
        $room = Room::create([
            'number' => '101',
            'floor_id' => Floor::create(['number' => 1])->id,
            'room_type_id' => RoomType::create(['code' => 'std', 'name' => 'عادية'])->id,
            'room_status_id' => RoomStatus::create(['code' => 'available', 'name' => 'متاحة'])->id,
            'price_sdg' => 100,
        ]);

        $data = $this->actingAs($this->user)->postJson('/api/reservations', [
            'customer_id' => Customer::create(['name' => 'عميل'])->id,
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-03',
            'currency' => 'SDG',
            'rooms' => [['id' => $room->id]],
        ])->assertCreated()->json();

        $reservation = Reservation::findOrFail($data['id']);
        $reservation->update(['status' => $status]);

        return $reservation;
    }

    public function test_deleting_a_reservation_removes_its_statement_entries(): void
    {
        $reservation = $this->book();
        $this->assertSame(1, Transaction::where('reservation_id', $reservation->id)->count());

        $this->actingAs($this->user)->deleteJson("/api/reservations/{$reservation->id}")->assertOk();

        $this->assertModelMissing($reservation);
        $this->assertSame(0, Transaction::where('reservation_id', $reservation->id)->count());
    }

    public function test_cannot_delete_a_reservation_with_payments(): void
    {
        $reservation = $this->book();
        Transaction::create([
            'customer_id' => $reservation->customer_id,
            'reservation_id' => $reservation->id,
            'type' => 'credit',
            'amount' => 50,
            'method' => 'cash',
            'transaction_date' => now(),
        ]);

        $this->actingAs($this->user)->deleteJson("/api/reservations/{$reservation->id}")->assertStatus(422);

        $this->assertModelExists($reservation);
    }

    public function test_cannot_delete_a_checked_in_reservation(): void
    {
        $reservation = $this->book('checked_in');

        $this->actingAs($this->user)->deleteJson("/api/reservations/{$reservation->id}")->assertStatus(422);

        $this->assertModelExists($reservation);
    }
}
