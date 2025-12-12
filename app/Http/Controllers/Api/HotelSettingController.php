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
        if ($settings) {
            // Generate full URL matching the API base structure
            $baseUrl = request()->getSchemeAndHttpHost() . '/hotel-backend/public';
            $settings->logo_url = $settings->logo_path ? $baseUrl . '/storage/' . $settings->logo_path : null;
            $settings->stamp_url = $settings->stamp_path ? $baseUrl . '/storage/' . $settings->stamp_path : null;
            $settings->header_url = $settings->header_path ? $baseUrl . '/storage/' . $settings->header_path : null;
            $settings->footer_url = $settings->footer_path ? $baseUrl . '/storage/' . $settings->footer_path : null;
        }
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'official_name' => ['required','string','max:255'],
            'address_line' => ['nullable','string','max:255'],
            'city' => ['nullable','string','max:100'],
            'phone' => ['nullable','string','max:50'],
            'phone_2' => ['nullable','string','max:50'],
            'email' => ['nullable','email','max:255'],
            'logo' => ['nullable','image','max:2048'],
            'stamp' => ['nullable','image','max:2048'],
            'header' => ['nullable','image','max:2048'],
            'footer' => ['nullable','image','max:2048'],
        ]);

        $settings = HotelSetting::firstOrNew([]);

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $path = $request->file('logo')->store('logos', 'public');
            $settings->logo_path = $path;
        }

        if ($request->hasFile('stamp')) {
            if ($settings->stamp_path) {
                Storage::disk('public')->delete($settings->stamp_path);
            }
            $path = $request->file('stamp')->store('stamps', 'public');
            $settings->stamp_path = $path;
        }

        if ($request->hasFile('header')) {
            if ($settings->header_path) {
                Storage::disk('public')->delete($settings->header_path);
            }
            $path = $request->file('header')->store('headers', 'public');
            $settings->header_path = $path;
        }

        if ($request->hasFile('footer')) {
            if ($settings->footer_path) {
                Storage::disk('public')->delete($settings->footer_path);
            }
            $path = $request->file('footer')->store('footers', 'public');
            $settings->footer_path = $path;
        }

        $settings->fill($data);
        $settings->save();

        // Add full URLs for images - matching the API base structure
        $baseUrl = request()->getSchemeAndHttpHost() . '/hotel-backend/public';
        $settings->logo_url = $settings->logo_path ? $baseUrl . '/storage/' . $settings->logo_path : null;
        $settings->stamp_url = $settings->stamp_path ? $baseUrl . '/storage/' . $settings->stamp_path : null;
        $settings->header_url = $settings->header_path ? $baseUrl . '/storage/' . $settings->header_path : null;
        $settings->footer_url = $settings->footer_path ? $baseUrl . '/storage/' . $settings->footer_path : null;

        return response()->json($settings);
    }
}
