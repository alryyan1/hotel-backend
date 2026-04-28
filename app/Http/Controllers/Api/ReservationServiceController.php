<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReservationService;
use Illuminate\Http\Request;

class ReservationServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = ReservationService::with(['reservation.customer', 'room', 'service']);
        
        // Optionally filter by reservation
        if ($request->has('reservation_id')) {
            $query->where('reservation_id', $request->get('reservation_id'));
        }

        return response()->json($query->orderByDesc('id')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'room_id' => 'required|exists:rooms,id',
            'service_id' => 'required|exists:services,id',
            'amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reservationService = ReservationService::create($validated);
        
        return response()->json($reservationService->load(['reservation.customer', 'room', 'service']), 201);
    }

    public function show(ReservationService $reservationService)
    {
        return response()->json($reservationService->load(['reservation', 'room', 'service']));
    }

    public function update(Request $request, ReservationService $reservationService)
    {
        $validated = $request->validate([
            'room_id' => 'sometimes|exists:rooms,id',
            'service_id' => 'sometimes|exists:services,id',
            'amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reservationService->update($validated);
        return response()->json($reservationService->fresh()->load(['reservation', 'room', 'service']));
    }

    public function destroy(ReservationService $reservationService)
    {
        $reservationService->delete();
        return response()->json(null, 204);
    }
}
