<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNotificationNumber;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppNotificationNumberController extends Controller
{
    private const EVENTS = ['confirmation', 'cancellation', 'cleaning'];

    public function index(Request $request)
    {
        $query = WhatsAppNotificationNumber::query()->orderBy('event')->orderBy('id');

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'event' => ['required', 'string', Rule::in(self::EVENTS)],
            'phone' => ['required', 'string', 'max:30'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $number = WhatsAppNotificationNumber::create($data);

        return response()->json($number, 201);
    }

    public function destroy(WhatsAppNotificationNumber $number)
    {
        $number->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
