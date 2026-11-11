<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function openShift(User $user): Shift
    {
        return Shift::create(['opened_by' => $user->id, 'opened_at' => now(), 'status' => 'open', 'open_flag' => true]);
    }

    private function record(User $user, Customer $customer, string $type = 'credit'): Transaction
    {
        $this->actingAs($user);

        return Transaction::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'amount' => 1000,
            'method' => 'cash',
            'transaction_date' => now(),
        ]);
    }

    public function test_user_can_cancel_their_own_payment_in_the_open_shift(): void
    {
        $user = User::factory()->create();
        $this->openShift($user);
        $payment = $this->record($user, Customer::create(['name' => 'عميل']));

        $this->actingAs($user)->deleteJson("/api/transactions/{$payment->id}")->assertOk();

        $this->assertModelMissing($payment);
    }

    public function test_user_cannot_cancel_someone_elses_payment(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->openShift($alice);
        $payment = $this->record($alice, Customer::create(['name' => 'عميل']));

        $this->actingAs($bob)->deleteJson("/api/transactions/{$payment->id}")->assertForbidden();

        $this->assertModelExists($payment);
    }

    public function test_user_cannot_cancel_a_payment_from_a_closed_shift(): void
    {
        $user = User::factory()->create();
        $first = $this->openShift($user);
        $payment = $this->record($user, Customer::create(['name' => 'عميل']));
        $first->update(['status' => 'closed', 'open_flag' => null, 'closed_at' => now()]);
        $this->openShift($user);

        $this->actingAs($user)->deleteJson("/api/transactions/{$payment->id}")->assertForbidden();

        $this->assertModelExists($payment);
    }

    public function test_admin_can_cancel_any_payment_but_not_a_charge(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = Customer::create(['name' => 'عميل']);
        $this->openShift($user);
        $payment = $this->record($user, $customer);
        $charge = $this->record($user, $customer, 'debit');

        $this->actingAs($admin)->deleteJson("/api/transactions/{$charge->id}")->assertStatus(422);
        $this->actingAs($admin)->deleteJson("/api/transactions/{$payment->id}")->assertOk();

        $this->assertModelExists($charge);
        $this->assertModelMissing($payment);
    }

    public function test_ledger_payments_show_who_recorded_them(): void
    {
        $user = User::factory()->create(['name' => 'أحمد']);
        $customer = Customer::create(['name' => 'عميل']);
        $this->openShift($user);
        $payment = $this->record($user, $customer);

        $entries = collect($this->actingAs($user)->getJson("/api/customers/{$customer->id}/ledger")->assertOk()->json('ledger_entries'));

        $entry = $entries->firstWhere('id', $payment->id);
        $this->assertSame('أحمد', $entry['paid_by']);
        $this->assertSame($user->id, $entry['user_id']);
    }
}
