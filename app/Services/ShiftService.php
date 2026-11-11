<?php

namespace App\Services;

use App\Models\ReservationService;
use App\Models\Service;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftService
{
    /**
     * Minimum number of hours required between the start of one shift and the next,
     * to stop a shift being opened again by mistake (or deliberately) right after one
     * was just opened. Admins are exempt and can open/close at any time.
     */
    private const MIN_HOURS_BETWEEN_SHIFTS = 4;

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

        if (!$user->is_admin) {
            $this->assertMinimumGapSinceLastShift();
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
     * Enforce the minimum gap between the previous shift's start and a new one,
     * so non-admin users can't open several shifts within a short window.
     */
    private function assertMinimumGapSinceLastShift(): void
    {
        $lastShift = Shift::latest('opened_at')->first();
        if (!$lastShift) {
            return;
        }

        $minGapMinutes = self::MIN_HOURS_BETWEEN_SHIFTS * 60;
        $minutesSinceLastOpen = (int) floor($lastShift->opened_at->diffInMinutes(now()));

        if ($minutesSinceLastOpen >= $minGapMinutes) {
            return;
        }

        $remaining = $minGapMinutes - $minutesSinceLastOpen;
        $hours = intdiv($remaining, 60);
        $minutes = $remaining % 60;
        $hoursLabel = $hours === 1 ? 'ساعة' : 'ساعات';
        $minutesLabel = $minutes === 1 ? 'دقيقة' : 'دقائق';

        throw ValidationException::withMessages([
            'shift' => sprintf(
                'لا يمكن فتح وردية جديدة الآن. يجب الانتظار %d %s و %d %s (الحد الأدنى %d ساعات بين كل وردية والتي تليها)',
                $hours,
                $hoursLabel,
                $minutes,
                $minutesLabel,
                self::MIN_HOURS_BETWEEN_SHIFTS
            ),
        ]);
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

        return $this->summaries->summarize(null, null, $scope) + [
            'services_breakdown' => $this->servicesBreakdown($scope),
        ];
    }

    /**
     * Settled services grouped by service, with SDG and USD totals kept apart.
     */
    private function servicesBreakdown(array $scope): array
    {
        $rows = ReservationService::query()
            ->where($scope)
            ->whereNotNull('paid_at')
            ->select(
                'service_id',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(quantity) as quantity'),
                DB::raw("SUM(CASE WHEN currency = 'USD' THEN 0 ELSE amount END) as total_sdg"),
                DB::raw("SUM(CASE WHEN currency = 'USD' THEN amount_usd ELSE 0 END) as total_usd")
            )
            ->groupBy('service_id')
            ->get();

        $names = Service::whereIn('id', $rows->pluck('service_id'))->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'service_id' => $row->service_id,
                'name' => $names[$row->service_id] ?? '-',
                'count' => (int) $row->count,
                'quantity' => (int) $row->quantity,
                'total_sdg' => (float) $row->total_sdg,
                'total_usd' => (float) $row->total_usd,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Per-user summaries for everyone who collected money in the shift. Only payments and
     * settled services count — reservation debits and unpaid service requests are also
     * stamped with the shift but are not revenue, so they must not add (empty) rows.
     */
    public function userBreakdown(Shift $shift): array
    {
        $userIds = Transaction::where('shift_id', $shift->id)->where('type', 'credit')->whereNotNull('user_id')->distinct()->pluck('user_id')
            ->merge(ReservationService::where('shift_id', $shift->id)->whereNotNull('paid_at')->whereNotNull('user_id')->distinct()->pluck('user_id'))
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
