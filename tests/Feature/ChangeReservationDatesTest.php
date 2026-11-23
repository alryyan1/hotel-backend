<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeReservationDatesTest extends TestCase
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
        $floor = \App\Models\Floor::create(['number' => 1]);
        $type = \App\Models\RoomType::create(['code' => 'std', 'name' => 'عادية']);
        $roomStatus = \App\Models\RoomStatus::create(['code' => 'available', 'name' => 'متاحة']);
        $room = Room::create([
            'number' => '101',
            'floor_id' => $floor->id,
            'room_type_id' => $type->id,
            'room_status_id' => $roomStatus->id,
            'price_sdg' => 999,
        ]);
        $customer = Customer::create(['name' => 'عميل']);

        $data = $this->actingAs($this->user)->postJson('/api/reservations', [
            'customer_id' => $customer->id,
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-03',
            'currency' => 'SDG',
            'rooms' => [['id' => $room->id, 'rate' => 100]],
        ])->assertCreated()->json();

        $reservation = Reservation::findOrFail($data['id']);
        $reservation->update(['status' => $status]);

        return $reservation;
    }

    public function test_changing_dates_reprices_at_the_frozen_rate(): void
    {
        $reservation = $this->book();

        $this->actingAs($this->user)
            ->postJson("/api/reservations/{$reservation->id}/change-dates", [
                'check_in_date' => '2026-12-05',
                'check_out_date' => '2026-12-09',
            ])
            ->assertOk()
            ->assertJsonPath('total_amount', fn ($v) => (float) $v === 400.0);

        $reservation->refresh();
        $this->assertSame('2026-12-05', \Carbon\Carbon::parse($reservation->check_in_date)->toDateString());
        $this->assertSame('2026-12-09', \Carbon\Carbon::parse($reservation->check_out_date)->toDateString());

        $debits = Transaction::where('reservation_id', $reservation->id)->where('type', 'debit')->get();
        $this->assertCount(1, $debits);
        $this->assertEquals(400, $debits->first()->amount);
        $this->assertEquals(4, $debits->first()->nights);
    }

    public function test_cannot_change_dates_after_check_in(): void
    {
        $reservation = $this->book();
        $reservation->update(['status' => 'checked_in']);

        $this->actingAs($this->user)
            ->postJson("/api/reservations/{$reservation->id}/change-dates", [
                'check_in_date' => '2026-12-05',
                'check_out_date' => '2026-12-09',
            ])
            ->assertStatus(422);
    }
}
