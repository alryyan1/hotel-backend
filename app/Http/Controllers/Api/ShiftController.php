<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HotelSetting;
use App\Models\ReservationService;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShiftController extends Controller
{
    public function __construct(private ShiftService $shifts)
    {
    }

    /**
     * The open shift with a live summary (regular users: their own figures only).
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $shift = $this->shifts->current();

        if (!$shift) {
            return response()->json(['shift' => null]);
        }

        return response()->json([
            'shift' => $shift->load('opener:id,name'),
            'summary' => $this->shifts->summary($shift, $user->is_admin ? null : $user->id),
            'can_close' => $this->canClose($shift, $user),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $shift = $this->shifts->open($request->user());

        return response()->json($shift->load('opener:id,name'), 201);
    }

    public function close(Request $request, Shift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$this->canClose($shift, $user)) {
            return response()->json(['message' => 'لا يمكنك إغلاق هذه الوردية'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $shift = $this->shifts->close($shift, $user, $validated['notes'] ?? null);

        // totals holds every user's breakdown; regular users only get it via their scoped endpoints
        return response()->json($shift->load(['opener:id,name', 'closer:id,name'])->makeHidden('totals'));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Shift::with(['opener:id,name', 'closer:id,name'])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }

        $shifts = $query->paginate(20);

        $shifts->getCollection()->transform(function (Shift $shift) use ($user) {
            $shift->setAttribute('figures', $this->figuresFor($shift, $user));
            $shift->makeHidden('totals');
            return $shift;
        });

        return response()->json($shifts);
    }

    public function show(Request $request, Shift $shift): JsonResponse
    {
        $user = $request->user();
        $userId = $user->is_admin ? null : $user->id;

        $scoped = fn ($query) => $query->where('shift_id', $shift->id)
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $shift->load(['opener:id,name', 'closer:id,name']);
        $shift->makeHidden('totals');

        return response()->json([
            'shift' => $shift,
            'summary' => $this->figuresFor($shift, $user),
            'users' => $user->is_admin ? $this->usersFor($shift) : null,
            'transactions' => $scoped(Transaction::query())
                ->where('type', 'credit')
                ->with(['customer:id,name', 'user:id,name'])
                ->orderByDesc('id')
                ->get(),
            'services' => $scoped(ReservationService::query())
                ->whereNotNull('paid_at')
                ->with(['service', 'room', 'reservation.customer:id,name', 'user:id,name'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function exportPdf(Request $request, Shift $shift): Response
    {
        $user = $request->user();
        $shift->load(['opener:id,name', 'closer:id,name']);
        $summary = $this->figuresFor($shift, $user);
        $users = $user->is_admin ? $this->usersFor($shift) : [];
        $hotel = HotelSetting::first();

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('تقرير الوردية #' . $shift->id);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setRTL(true);
        $pdf->SetFont('arial', '', 10);
        $pdf->AddPage();

        $html = $this->pdfHeader($shift, $user, $hotel?->official_name)
            . $this->pdfTotals($summary)
            . $this->pdfMethodTable($summary)
            . ($users ? $this->pdfUsersTable($users) : '')
            . $this->pdfFooter($shift);

        $pdf->writeHTML($html, true, false, true, false, '');

        return response($pdf->Output('shift_' . $shift->id . '.pdf', 'S'), 200)
            ->header('Content-Type', 'application/pdf');
    }

    private function canClose(Shift $shift, User $user): bool
    {
        return $shift->isOpen() && (int) $shift->opened_by === (int) $user->id;
    }

    /**
     * Admins get the whole shift, regular users their own records. Closed shifts read the
     * snapshot taken at close time; a user absent from the snapshot gets a (zero) live summary.
     */
    private function figuresFor(Shift $shift, User $user): array
    {
        if ($shift->isOpen() || !$shift->totals) {
            return $this->shifts->summary($shift, $user->is_admin ? null : $user->id);
        }

        if ($user->is_admin) {
            return $shift->totals['summary'];
        }

        $entry = collect($shift->totals['users'] ?? [])->firstWhere('user_id', $user->id);

        return $entry['summary'] ?? $this->shifts->summary($shift, $user->id);
    }

    private function usersFor(Shift $shift): array
    {
        return $shift->isOpen() || !$shift->totals
            ? $this->shifts->userBreakdown($shift)
            : ($shift->totals['users'] ?? []);
    }

    private const PDF_HEAD_BG = '#1e3a5f';
    private const PDF_SOFT_BG = '#eef2f7';
    private const PDF_BORDER = '#c7d0db';

    private function pdfSection(string $title): string
    {
        return '<br /><table cellpadding="4"><tr><td style="font-size:12pt;font-weight:bold;color:' . self::PDF_HEAD_BG
            . ';border-bottom:1.5px solid ' . self::PDF_HEAD_BG . ';">' . e($title) . '</td></tr></table><br />';
    }

    /**
     * A bordered table with a dark header row and an optional shaded totals row.
     *
     * @param  array<string, int>  $columns  label => width (%)
     * @param  array<int, array<int, string>>  $rows  already-formatted cell values
     * @param  array<int, array{0: string, 1: int}>  $groups  optional [label, width %] header row above the columns
     */
    private function pdfTable(array $columns, array $rows, ?array $footer = null, string $empty = 'لا توجد عمليات', array $groups = []): string
    {
        $cell = 'border:0.5px solid ' . self::PDF_BORDER . ';';
        $html = '<table cellpadding="5" style="' . $cell . '">';
        if ($groups) {
            $html .= '<tr>';
            foreach ($groups as [$label, $width]) {
                $html .= '<td width="' . $width . '%" align="center" style="' . $cell . 'background-color:' . self::PDF_HEAD_BG
                    . ';color:#ffffff;font-weight:bold;font-size:11pt;">' . e($label) . '</td>';
            }
            $html .= '</tr>';
        }
        // Also accepts a list of [label, width] pairs, for headers that repeat a label
        if (array_is_list($columns)) {
            $columns = array_map(null, array_column($columns, 0), array_column($columns, 1));
        } else {
            $columns = array_map(null, array_keys($columns), array_values($columns));
        }

        $html .= '<tr>';
        foreach ($columns as [$label, $width]) {
            $html .= '<td width="' . $width . '%" align="center" style="' . $cell . 'background-color:' . ($groups ? '#2f5583' : self::PDF_HEAD_BG)
                . ';color:#ffffff;font-weight:bold;">' . e($label) . '</td>';
        }
        $html .= '</tr>';

        $widths = array_column($columns, 1);
        if (!$rows) {
            $html .= '<tr><td width="100%" align="center" style="' . $cell . 'color:#777777;">' . e($empty) . '</td></tr>';
        }
        foreach ($rows as $i => $row) {
            $bg = $i % 2 ? 'background-color:#f8fafc;' : '';
            $html .= '<tr>';
            foreach ($row as $j => $value) {
                $html .= '<td width="' . $widths[$j] . '%" align="center" style="' . $cell . $bg . '">' . $value . '</td>';
            }
            $html .= '</tr>';
        }
        if ($footer && $rows) {
            $html .= '<tr>';
            foreach ($footer as $j => $value) {
                $html .= '<td width="' . $widths[$j] . '%" align="center" style="' . $cell . 'background-color:' . self::PDF_SOFT_BG
                    . ';font-weight:bold;">' . $value . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</table>';
    }

    private function pdfHeader(Shift $shift, User $user, ?string $hotelName): string
    {
        $status = $shift->isOpen() ? 'مفتوحة' : 'مغلقة';
        $closed = $shift->closed_at
            ? e($shift->closer->name ?? '-') . ' — ' . $shift->closed_at->format('Y-m-d H:i')
            : '—';
        $label = 'style="color:#555555;"';

        $html = '<table cellpadding="8"><tr><td align="center" style="background-color:' . self::PDF_HEAD_BG . ';color:#ffffff;">'
            . ($hotelName ? '<span style="font-size:11pt;">' . e($hotelName) . '</span><br />' : '')
            . '<span style="font-size:17pt;font-weight:bold;">تقرير الوردية #' . $shift->id . '</span>'
            . '</td></tr></table><br /><br />';

        $html .= '<table cellpadding="5" style="border:0.5px solid ' . self::PDF_BORDER . ';background-color:' . self::PDF_SOFT_BG . ';">'
            . '<tr><td width="50%"><span ' . $label . '>الفتح:</span> ' . e($shift->opener->name ?? '-') . ' — ' . $shift->opened_at?->format('Y-m-d H:i') . '</td>'
            . '<td width="50%"><span ' . $label . '>الإغلاق:</span> ' . $closed . '</td></tr>'
            . '<tr><td width="50%"><span ' . $label . '>الحالة:</span> ' . $status . '</td>'
            . '<td width="50%">' . ($user->is_admin ? '' : '<span ' . $label . '>المستخدم:</span> ' . e($user->name)) . '</td></tr>';

        return $html . '</table>';
    }

    /**
     * One currency box: reservations, services and a highlighted total.
     */
    private function pdfCurrencyBox(string $title, string $unit, float $reservations, float $services): string
    {
        $row = fn (string $label, float $value) => '<tr><td width="55%" style="color:#555555;border-bottom:0.5px solid ' . self::PDF_BORDER . ';">' . $label
            . '</td><td width="45%" align="left" style="border-bottom:0.5px solid ' . self::PDF_BORDER . ';">' . $this->money($value) . ' ' . $unit . '</td></tr>';

        return '<table cellpadding="6" style="border:0.5px solid ' . self::PDF_BORDER . ';">'
            . '<tr><td width="100%" style="background-color:' . self::PDF_HEAD_BG . ';color:#ffffff;font-weight:bold;font-size:11pt;">' . e($title) . '</td></tr>'
            . $row('إيرادات الحجوزات', $reservations)
            . $row('إيرادات الخدمات', $services)
            . '<tr><td width="55%" style="background-color:' . self::PDF_SOFT_BG . ';font-weight:bold;">الإجمالي</td>'
            . '<td width="45%" align="left" style="background-color:' . self::PDF_SOFT_BG . ';font-weight:bold;font-size:13pt;color:' . self::PDF_HEAD_BG . ';">'
            . $this->money($reservations + $services) . ' ' . $unit . '</td></tr></table>';
    }

    /**
     * SDG and USD side by side — the two currencies are never summed together.
     */
    private function pdfTotals(array $summary): string
    {
        $sdg = $this->pdfCurrencyBox('الجنيه السوداني (SDG)', 'ج.س', (float) ($summary['total_revenue'] ?? 0), (float) ($summary['total_service_revenue'] ?? 0));
        $usd = $this->pdfCurrencyBox('الدولار (USD)', '$', (float) ($summary['total_revenue_usd_cash'] ?? 0), (float) ($summary['total_service_revenue_usd'] ?? 0));

        return $this->pdfSection('ملخص الإيرادات')
            . '<table><tr><td width="49%">' . $sdg . '</td><td width="2%"></td><td width="49%">' . $usd . '</td></tr></table>';
    }

    /** Group header shared by the method and user tables: first column, then SDG and USD groups. */
    private function pdfCurrencyGroups(string $first, int $firstWidth): array
    {
        $group = (int) ((100 - $firstWidth) / 2);

        return [[$first, $firstWidth], ['الجنيه (ج.س)', $group], ['الدولار ($)', 100 - $firstWidth - $group]];
    }

    private function pdfMethodTable(array $summary): string
    {
        $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري', 'unknown' => 'غير معروف'];
        $usdReservations = (float) ($summary['total_revenue_usd_cash'] ?? 0);
        $usdServices = (float) ($summary['total_service_revenue_usd'] ?? 0);
        $usdTotal = $usdReservations + $usdServices;

        $methods = array_unique(array_merge(
            array_keys($summary['revenue_by_method'] ?? []),
            array_keys($summary['services_by_method'] ?? []),
            // USD is only ever settled as cash, so dollars always land on the cash row
            $usdTotal > 0 ? ['cash'] : []
        ));

        $rows = array_map(function ($method) use ($summary, $methodLabels, $usdReservations, $usdServices, $usdTotal) {
            $rev = $summary['revenue_by_method'][$method] ?? 0;
            $srv = $summary['services_by_method'][$method] ?? 0;
            $usd = fn (float $value) => $method === 'cash' ? $this->money($value) : '<span style="color:#999999;">—</span>';

            return [
                '<b>' . ($methodLabels[$method] ?? e($method)) . '</b>',
                $this->money($rev),
                $this->money($srv),
                '<b>' . $this->money($rev + $srv) . '</b>',
                $usd($usdReservations),
                $usd($usdServices),
                $method === 'cash' ? '<b>' . $this->money($usdTotal) . '</b>' : $usd(0),
            ];
        }, array_values($methods));

        $footer = [
            'الإجمالي',
            $this->money($summary['total_revenue'] ?? 0),
            $this->money($summary['total_service_revenue'] ?? 0),
            $this->money($this->netOf($summary)),
            $this->money($usdReservations),
            $this->money($usdServices),
            $this->money($usdTotal),
        ];

        return $this->pdfSection('حسب طريقة الدفع')
            . $this->pdfTable($this->pdfCurrencyColumns(16), $rows, $footer, 'لا توجد عمليات', $this->pdfCurrencyGroups('طريقة الدفع', 16));
    }

    /** The first column plus reservations / services / total under each currency group. */
    private function pdfCurrencyColumns(int $firstWidth): array
    {
        $each = (100 - $firstWidth) / 6;
        $group = [['الحجوزات', $each], ['الخدمات', $each], ['الإجمالي', $each]];

        return [['', $firstWidth], ...$group, ...$group];
    }

    private function pdfUsersTable(array $users): string
    {
        $rows = array_map(function ($row) {
            $s = $row['summary'];
            return [
                '<b>' . e($row['name']) . '</b>',
                $this->money($s['total_revenue'] ?? 0),
                $this->money($s['total_service_revenue'] ?? 0),
                '<b>' . $this->money($this->netOf($s)) . '</b>',
                $this->money($s['total_revenue_usd_cash'] ?? 0),
                $this->money($s['total_service_revenue_usd'] ?? 0),
                '<b>' . $this->money($this->usdOf($s)) . '</b>',
            ];
        }, $users);

        $sum = fn (callable $pick) => $this->money(array_sum(array_map(fn ($row) => $pick($row['summary']), $users)));
        $footer = count($users) > 1 ? [
            'الإجمالي',
            $sum(fn ($s) => $s['total_revenue'] ?? 0),
            $sum(fn ($s) => $s['total_service_revenue'] ?? 0),
            $sum(fn ($s) => $this->netOf($s)),
            $sum(fn ($s) => $s['total_revenue_usd_cash'] ?? 0),
            $sum(fn ($s) => $s['total_service_revenue_usd'] ?? 0),
            $sum(fn ($s) => $this->usdOf($s)),
        ] : null;

        return $this->pdfSection('إيرادات المستخدمين')
            . $this->pdfTable($this->pdfCurrencyColumns(16), $rows, $footer, 'لا توجد إيرادات', $this->pdfCurrencyGroups('المستخدم', 16));
    }

    private function pdfFooter(Shift $shift): string
    {
        $html = '';
        if ($shift->notes) {
            $html .= $this->pdfSection('ملاحظات') . '<div>' . nl2br(e($shift->notes)) . '</div>';
        }

        return $html . '<br /><br /><table cellpadding="4"><tr>'
            . '<td width="50%" align="center">توقيع المستلم<br /><br />......................................</td>'
            . '<td width="50%" align="center">توقيع المسؤول<br /><br />......................................</td>'
            . '</tr><tr><td width="100%" align="center" style="font-size:8pt;color:#888888;">طُبع في ' . now()->format('Y-m-d H:i') . '</td></tr></table>';
    }

    /**
     * Reservation payments + services. Computed rather than read from net_profit, since
     * snapshots of shifts closed before refunds were dropped still have them deducted there.
     */
    private function netOf(array $summary): float
    {
        return (float) ($summary['total_revenue'] ?? 0) + (float) ($summary['total_service_revenue'] ?? 0);
    }

    /** USD cash received for reservations + USD-billed services. */
    private function usdOf(array $summary): float
    {
        return (float) ($summary['total_revenue_usd_cash'] ?? 0) + (float) ($summary['total_service_revenue_usd'] ?? 0);
    }

    private function money($value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }
}
