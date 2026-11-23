<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SoftDeleteCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function entry(Customer $customer, array $attrs = []): Transaction
    {
        return Transaction::create($attrs + [
            'customer_id' => $customer->id,
            'type' => 'debit',
            'amount' => 100,
            'transaction_date' => now(),
        ]);
    }

    public function test_deleting_a_customer_hides_their_entries_and_restore_brings_them_back(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $customer = Customer::create(['name' => 'عميل']);
        $this->entry($customer);
        $this->entry($customer, ['type' => 'credit', 'method' => 'cash']);

        $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}")->assertOk();

        $this->assertSoftDeleted($customer);
        $this->assertSame(0, Transaction::where('customer_id', $customer->id)->count());
        $this->assertSame(2, Transaction::onlyTrashed()->where('customer_id', $customer->id)->count());
        $this->actingAs($user)->getJson('/api/customers/trashed')->assertJsonPath('data.0.id', $customer->id);

        $this->actingAs($user)->postJson("/api/customers/{$customer->id}/restore")->assertOk();

        $this->assertNotSoftDeleted($customer);
        $this->assertSame(2, Transaction::where('customer_id', $customer->id)->count());
    }

    public function test_force_delete_removes_past_reservations_and_all_entries(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $customer = Customer::create(['name' => 'عميل']);
        $reservation = Reservation::create([
            'customer_id' => $customer->id,
            'check_in_date' => '2026-10-01',
            'check_out_date' => '2026-10-03',
            'status' => 'checked_out',
        ]);
        $this->entry($customer, ['reservation_id' => $reservation->id]);
        $this->entry($customer, ['type' => 'credit', 'method' => 'cash', 'reservation_id' => $reservation->id]);

        $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}")->assertOk();
        $this->actingAs($user)->getJson('/api/customers/trashed')->assertJsonPath('data.0.reservations_count', 1);
        $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}/force")->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertModelMissing($reservation);
        $this->assertSame(0, Transaction::withTrashed()->where('customer_id', $customer->id)->count());
    }

    public function test_cannot_delete_a_customer_with_an_active_reservation(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $customer = Customer::create(['name' => 'عميل']);
        Reservation::create([
            'customer_id' => $customer->id,
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-03',
            'status' => 'confirmed',
        ]);

        $this->actingAs($user)->deleteJson("/api/customers/{$customer->id}")->assertStatus(422);

        $this->assertNotSoftDeleted($customer);
    }
}
