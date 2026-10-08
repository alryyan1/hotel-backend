<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cost;
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
                ->whereIn('type', ['credit', 'refund'])
                ->with(['customer:id,name', 'user:id,name'])
                ->orderByDesc('id')
                ->get(),
            'costs' => $scoped(Cost::query())
                ->with(['costCategory', 'user:id,name'])
                ->orderByDesc('id')
                ->get(),
            'services' => $scoped(ReservationService::query())
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

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hotel Management System');
        $pdf->SetTitle('تقرير الوردية #' . $shift->id);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setRTL(true);
        $pdf->AddPage();

        $pdf->SetFont('arial', 'B', 16);
        $pdf->Cell(0, 10, 'تقرير الوردية #' . $shift->id, 0, 1, 'C');

        $pdf->SetFont('arial', '', 10);
        $pdf->Cell(0, 7, 'فتح بواسطة: ' . ($shift->opener->name ?? '-') . '   في: ' . $shift->opened_at?->format('Y-m-d H:i'), 0, 1, 'R');
        if ($shift->closed_at) {
            $pdf->Cell(0, 7, 'أغلق بواسطة: ' . ($shift->closer->name ?? '-') . '   في: ' . $shift->closed_at->format('Y-m-d H:i'), 0, 1, 'R');
        } else {
            $pdf->Cell(0, 7, 'الحالة: مفتوحة', 0, 1, 'R');
        }
        if (!$user->is_admin) {
            $pdf->Cell(0, 7, 'المستخدم: ' . $user->name, 0, 1, 'R');
        }
        $pdf->Ln(4);

        $this->pdfMethodTable($pdf, $summary);

        if ($users) {
            $pdf->Ln(6);
            $pdf->SetFont('arial', 'B', 12);
            $pdf->Cell(0, 9, 'إيرادات المستخدمين', 0, 1, 'R');
            $pdf->SetFont('arial', 'B', 10);
            $pdf->SetFillColor(230, 230, 230);
            $headers = ['المستخدم' => 35, 'مدفوعات الحجوزات' => 35, 'الخدمات' => 30, 'المصروف' => 25, 'مسترجع' => 25, 'الصافي' => 30];
            foreach ($headers as $label => $width) {
                $pdf->Cell($width, 9, $label, 1, 0, 'C', true);
            }
            $pdf->Ln();
            $pdf->SetFont('arial', '', 10);
            foreach ($users as $row) {
                $s = $row['summary'];
                $pdf->Cell(35, 8, $row['name'], 1, 0, 'C');
                $pdf->Cell(35, 8, $this->money($s['total_revenue']), 1, 0, 'C');
                $pdf->Cell(30, 8, $this->money($s['total_service_revenue']), 1, 0, 'C');
                $pdf->Cell(25, 8, $this->money($s['total_expenses']), 1, 0, 'C');
                $pdf->Cell(25, 8, $this->money($s['total_refunds']), 1, 0, 'C');
                $pdf->Cell(30, 8, $this->money($s['net_profit']), 1, 1, 'C');
            }
        }

        if ($shift->notes) {
            $pdf->Ln(6);
            $pdf->SetFont('arial', '', 10);
            $pdf->MultiCell(0, 7, 'ملاحظات: ' . $shift->notes, 0, 'R');
        }

        return response($pdf->Output('shift_' . $shift->id . '.pdf', 'S'), 200)
            ->header('Content-Type', 'application/pdf');
    }

    private function canClose(Shift $shift, User $user): bool
    {
        return $shift->isOpen() && ($user->is_admin || (int) $shift->opened_by === (int) $user->id);
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

    private function pdfMethodTable(\TCPDF $pdf, array $summary): void
    {
        $methodLabels = ['cash' => 'نقدي', 'bankak' => 'بنكك', 'Ocash' => 'أوكاش', 'fawri' => 'فوري', 'unknown' => 'غير معروف'];
        $widths = [35, 30, 30, 30, 25, 30];

        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(230, 230, 230);
        foreach (['طريقة الدفع', 'مدفوعات الحجوزات', 'الخدمات', 'المصروف', 'مسترجع', 'الصافي'] as $i => $label) {
            $pdf->Cell($widths[$i], 10, $label, 1, $i === 5 ? 1 : 0, 'C', true);
        }

        $methods = array_unique(array_merge(
            array_keys($summary['revenue_by_method'] ?? []),
            array_keys($summary['services_by_method'] ?? []),
            array_keys($summary['expenses_by_method'] ?? []),
            array_keys($summary['refunds_by_method'] ?? [])
        ));

        $pdf->SetFont('arial', '', 10);
        foreach ($methods as $method) {
            $rev = $summary['revenue_by_method'][$method] ?? 0;
            $srv = $summary['services_by_method'][$method] ?? 0;
            $exp = $summary['expenses_by_method'][$method] ?? 0;
            $ref = $summary['refunds_by_method'][$method] ?? 0;
            $row = [$methodLabels[$method] ?? $method, $rev, $srv, $exp, $ref, $rev + $srv - $exp - $ref];
            foreach ($row as $i => $value) {
                $pdf->Cell($widths[$i], 8, $i === 0 ? $value : $this->money($value), 1, $i === 5 ? 1 : 0, 'C');
            }
        }

        $pdf->SetFont('arial', 'B', 10);
        $pdf->SetFillColor(245, 245, 245);
        $totals = ['الإجمالي', $summary['total_revenue'], $summary['total_service_revenue'], $summary['total_expenses'], $summary['total_refunds'], $summary['net_profit']];
        foreach ($totals as $i => $value) {
            $pdf->Cell($widths[$i], 8, $i === 0 ? $value : $this->money($value), 1, $i === 5 ? 1 : 0, 'C', true);
        }
    }

    private function money($value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }
}
