<?php

namespace App\Services;

use App\Models\Cost;
use App\Models\ReservationService;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    public function __construct(private FinancialSummaryService $summaries = new FinancialSummaryService())
    {
    }

    public function current(): ?Shift
    {
        return Shift::current();
    }

    public function open(User $user): Shift
    {
        if (Shift::current()) {
            throw ValidationException::withMessages(['shift' => 'توجد وردية مفتوحة بالفعل، يجب إغلاقها أولاً']);
        }

        try {
            return Shift::create([
                'opened_by' => $user->id,
                'opened_at' => now(),
                'status' => 'open',
                'open_flag' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request opened a shift between the check above and this insert
            throw ValidationException::withMessages(['shift' => 'توجد وردية مفتوحة بالفعل، يجب إغلاقها أولاً']);
        }
    }

    /**
     * Shift totals, optionally limited to one user's records.
     */
    public function summary(Shift $shift, ?int $userId = null): array
    {
        $scope = ['shift_id' => $shift->id];
        if ($userId) {
            $scope['user_id'] = $userId;
        }

        return $this->summaries->summarize(null, null, $scope);
    }

    /**
     * Per-user summaries for everyone who recorded a financial operation in the shift.
     */
    public function userBreakdown(Shift $shift): array
    {
        $userIds = collect([Transaction::class, Cost::class, ReservationService::class])
            ->flatMap(fn ($model) => $model::where('shift_id', $shift->id)->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique()
            ->values();

        return User::whereIn('id', $userIds)->orderBy('name')->get()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'summary' => $this->summary($shift, $user->id),
            ])
            ->all();
    }

    public function close(Shift $shift, User $user, ?string $notes = null): Shift
    {
        if (!$shift->isOpen()) {
            throw ValidationException::withMessages(['shift' => 'هذه الوردية مغلقة بالفعل']);
        }

        return DB::transaction(function () use ($shift, $user, $notes) {
            $shift->update([
                'closed_by' => $user->id,
                'closed_at' => now(),
                // Snapshot so the closed shift's report never changes if records are edited later
                'totals' => [
                    'summary' => $this->summary($shift),
                    'users' => $this->userBreakdown($shift),
                ],
                'status' => 'closed',
                'open_flag' => null,
                'notes' => $notes,
            ]);

            return $shift;
        });
    }
}
