<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Service::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:services,name',
        ]);

        $service = Service::create($validated);
        return response()->json($service, 201);
    }

    public function show(Service $service)
    {
        return response()->json($service);
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:services,name,' . $service->id,
        ]);

        $service->update($validated);
        return response()->json($service);
    }

    public function destroy(Service $service)
    {
        if ($service->reservationServices()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف هذه الخدمة نظراً لارتباطها بطلبات حجوزات مسجلة'], 422);
        }

        $service->delete();
        return response()->json(null, 204);
    }
}
