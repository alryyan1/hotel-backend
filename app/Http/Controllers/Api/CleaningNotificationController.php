<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CleaningNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CleaningNotificationController extends Controller
{
    /**
     * Display a listing of cleaning notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CleaningNotification::with(['room', 'reservation.customer'])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Only pending by default
        if (!$request->has('status')) {
            $query->where('status', 'pending');
        }

        $notifications = $query->paginate(20);
        return response()->json($notifications);
    }

    /**
     * Get pending notifications count.
     */
    public function count(): JsonResponse
    {
        $count = CleaningNotification::where('status', 'pending')->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Mark notification as completed.
     */
    public function complete(CleaningNotification $cleaningNotification, Request $request): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $cleaningNotification->markAsCompleted($request->notes);

        return response()->json($cleaningNotification->load(['room', 'reservation.customer']));
    }

    /**
     * Dismiss notification.
     */
    public function dismiss(CleaningNotification $cleaningNotification): JsonResponse
    {
        $cleaningNotification->dismiss();

        return response()->json($cleaningNotification->load(['room', 'reservation.customer']));
    }

    /**
     * Display the specified notification.
     */
    public function show(CleaningNotification $cleaningNotification): JsonResponse
    {
        return response()->json($cleaningNotification->load(['room', 'reservation.customer']));
    }
}
