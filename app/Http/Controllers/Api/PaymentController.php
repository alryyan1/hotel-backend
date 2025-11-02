<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['customer', 'reservation']);

        // Filter by customer if provided
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by reservation if provided
        if ($request->has('reservation_id')) {
            $query->where('reservation_id', $request->reservation_id);
        }

        $payments = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($payments);
    }

    /**
     * Store a newly created payment.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'required|in:cash,bankak,Ocash,fawri',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'nullable|string|size:3|default:USD',
                'status' => 'nullable|in:pending,completed,failed,refunded|default:completed',
                'notes' => 'nullable|string',
                'reference' => 'nullable|string|unique:payments,reference',
            ]);

            // Generate reference if not provided
            if (!isset($validated['reference'])) {
                $validated['reference'] = 'PAY-' . strtoupper(Str::random(8));
            }

            // Set default currency if not provided
            if (!isset($validated['currency'])) {
                $validated['currency'] = 'USD';
            }

            // Set default status if not provided
            if (!isset($validated['status'])) {
                $validated['status'] = 'completed';
            }

            $payment = Payment::create($validated);
            return response()->json($payment->load(['customer', 'reservation']), 201);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Display the specified payment.
     */
    public function show(Payment $payment): JsonResponse
    {
        return response()->json($payment->load(['customer', 'reservation']));
    }

    /**
     * Update the specified payment.
     */
    public function update(Request $request, Payment $payment): JsonResponse
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'sometimes|exists:customers,id',
                'reservation_id' => 'nullable|exists:reservations,id',
                'method' => 'sometimes|in:cash,bankak,Ocash,fawri',
                'amount' => 'sometimes|numeric|min:0.01',
                'currency' => 'nullable|string|size:3',
                'status' => 'nullable|in:pending,completed,failed,refunded',
                'notes' => 'nullable|string',
                'reference' => 'sometimes|string|unique:payments,reference,' . $payment->id,
            ]);

            $payment->update($validated);
            return response()->json($payment->load(['customer', 'reservation']));
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Validation failed', 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Remove the specified payment.
     */
    public function destroy(Payment $payment): JsonResponse
    {
        $payment->delete();
        return response()->json(['message' => 'Payment deleted successfully']);
    }

    /**
     * Get payments for a specific customer.
     */
    public function getCustomerPayments(Customer $customer): JsonResponse
    {
        $payments = Payment::where('customer_id', $customer->id)
            ->with(['reservation'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json($payments);
    }
}