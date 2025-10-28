<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HotelSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HotelSettingController extends Controller
{
    public function show()
    {
        $settings = HotelSetting::first();
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'official_name' => ['required','string','max:255'],
            'trade_name' => ['nullable','string','max:255'],
            'address_line' => ['nullable','string','max:255'],
            'city' => ['nullable','string','max:100'],
            'postal_code' => ['nullable','string','max:20'],
            'country' => ['nullable','string','max:100'],
            'phone' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'website' => ['nullable','url','max:255'],
            'cancellation_policy' => ['nullable','string'],
            'logo' => ['nullable','image','max:2048'],
        ]);

        $settings = HotelSetting::firstOrNew([]);

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $path = $request->file('logo')->store('logos', 'public');
            $settings->logo_path = $path;
        }

        $settings->fill($data);
        $settings->save();

        return response()->json($settings);
    }
}
