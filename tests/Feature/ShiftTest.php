<?php

namespace Tests\Feature;

use App\Models\Cost;
use App\Models\Customer;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $admin = false): User
    {
        return User::factory()->create(['is_admin' => $admin]);
    }

    private function credit(User $user, float $amount, string $method = 'cash'): Transaction
    {
        $this->actingAs($user);

        return Transaction::create([
            'customer_id' => Customer::create(['name' => 'عميل ' . uniqid()])->id,
            'type' => 'credit',
            'amount' => $amount,
            'method' => $method,
            'transaction_date' => now(),
        ]);
    }

    private function cost(User $user, float $amount, string $method = 'cash'): Cost
    {
        $this->actingAs($user);

        return Cost::create([
            'description' => 'مصروف',
            'amount' => $amount,
            'date' => now()->toDateString(),
            'payment_method' => $method,
        ]);
    }

    public function test_only_one_shift_can_be_open(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/shifts/open')->assertCreated();
        $this->actingAs($this->makeUser())->postJson('/api/shifts/open')->assertStatus(422);

        $this->assertSame(1, Shift::where('status', 'open')->count());
    }

    public function test_a_new_shift_can_open_after_closing(): void
    {
        $user = $this->makeUser();
        $shiftId = $this->actingAs($user)->postJson('/api/shifts/open')->json('id');

        $this->actingAs($user)->postJson("/api/shifts/{$shiftId}/close")->assertOk();
        $this->actingAs($user)->postJson('/api/shifts/open')->assertCreated();
    }

    public function test_records_are_attributed_to_the_user_and_open_shift(): void
    {
        $user = $this->makeUser();
        $shift = Shift::create(['opened_by' => $user->id, 'opened_at' => now(), 'status' => 'open', 'open_flag' => true]);

        $transaction = $this->credit($user, 100);
        $cost = $this->cost($user, 20);

        $this->assertSame($user->id, (int) $transaction->user_id);
        $this->assertSame($shift->id, (int) $transaction->shift_id);
        $this->assertSame($user->id, (int) $cost->user_id);
        $this->assertSame($shift->id, (int) $cost->shift_id);
    }

    public function test_regular_user_sees_only_their_own_revenue(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $admin = $this->makeUser(admin: true);
        Shift::create(['opened_by' => $alice->id, 'opened_at' => now(), 'status' => 'open', 'open_flag' => true]);

        $this->credit($alice, 300);
        $this->credit($bob, 500, 'bankak');

        $this->actingAs($alice)->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('summary.total_revenue', 300);

        $this->actingAs($bob)->getJson('/api/shifts/current')
            ->assertJsonPath('summary.total_revenue', 500)
            ->assertJsonPath('can_close', false);

        $this->actingAs($admin)->getJson('/api/shifts/current')
            ->assertJsonPath('summary.total_revenue', 800);
    }

    public function test_shifts_are_calculated_separately(): void
    {
        $user = $this->makeUser();

        $first = $this->actingAs($user)->postJson('/api/shifts/open')->json('id');
        $this->credit($user, 1000);
        $this->actingAs($user)->postJson("/api/shifts/{$first}/close")->assertOk();

        $this->actingAs($user)->postJson('/api/shifts/open');
        $this->credit($user, 50);

        $this->actingAs($user)->getJson('/api/shifts/current')->assertJsonPath('summary.total_revenue', 50);
        $this->actingAs($user)->getJson("/api/shifts/{$first}")->assertJsonPath('summary.total_revenue', 1000);
    }

    public function test_closing_snapshots_the_shift_totals(): void
    {
        $user = $this->makeUser();
        $shiftId = $this->actingAs($user)->postJson('/api/shifts/open')->json('id');

        $this->credit($user, 1000);
        $this->credit($user, 400, 'bankak');
        $this->cost($user, 150);

        $this->actingAs($user)->postJson("/api/shifts/{$shiftId}/close")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $shift = Shift::find($shiftId);
        $this->assertEquals(1250, $shift->totals['summary']['net_profit']);
        $this->assertEquals(1400, $shift->totals['summary']['total_revenue']);
        $this->assertCount(1, $shift->totals['users']);
    }

    public function test_only_opener_or_admin_can_close(): void
    {
        $opener = $this->makeUser();
        $shiftId = $this->actingAs($opener)->postJson('/api/shifts/open')->json('id');

        $this->actingAs($this->makeUser())->postJson("/api/shifts/{$shiftId}/close")->assertForbidden();
        $this->actingAs($this->makeUser(admin: true))->postJson("/api/shifts/{$shiftId}/close")->assertOk();
    }

    public function test_financial_operations_require_an_open_shift(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/costs', [
            'description' => 'مصروف',
            'amount' => 10,
            'date' => now()->toDateString(),
        ])->assertStatus(423)->assertJsonPath('code', 'shift_required');

        $this->actingAs($user)->postJson('/api/shifts/open');

        $this->actingAs($user)->postJson('/api/costs', [
            'description' => 'مصروف',
            'amount' => 10,
            'date' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_admin_is_exempt_from_open_shift_requirement(): void
    {
        $this->actingAs($this->makeUser(admin: true))->postJson('/api/costs', [
            'description' => 'مصروف',
            'amount' => 10,
            'date' => now()->toDateString(),
        ])->assertCreated();
    }

    public function test_list_and_pdf_respect_user_scope(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $shiftId = $this->actingAs($alice)->postJson('/api/shifts/open')->json('id');
        $this->credit($alice, 300);
        $this->credit($bob, 500);
        $this->actingAs($alice)->postJson("/api/shifts/{$shiftId}/close");

        $this->actingAs($bob)->getJson('/api/shifts')
            ->assertOk()
            ->assertJsonPath('data.0.figures.total_revenue', 500)
            ->assertJsonMissingPath('data.0.totals');

        $this->actingAs($bob)->getJson("/api/shifts/{$shiftId}")
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('users', null);

        $this->actingAs($this->makeUser(admin: true))->get("/api/shifts/{$shiftId}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_existing_accounting_summary_is_unchanged_by_shift_scoping(): void
    {
        $user = $this->makeUser(admin: true);
        $this->credit($user, 200);  // no shift open: unattributed to a shift
        $this->actingAs($user)->postJson('/api/shifts/open');
        $this->credit($user, 300);

        $this->actingAs($user)->getJson('/api/accounting/summary')
            ->assertJsonPath('total_revenue', 500);
    }
}
