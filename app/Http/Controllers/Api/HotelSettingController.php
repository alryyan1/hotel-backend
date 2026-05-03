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
            $settings->logo_url = $settings->logo_path ? asset('storage/' . $settings->logo_path) : null;
            $settings->stamp_url = $settings->stamp_path ? asset('storage/' . $settings->stamp_path) : null;
            $settings->header_url = $settings->header_path ? asset('storage/' . $settings->header_path) : null;
            $settings->footer_url = $settings->footer_path ? asset('storage/' . $settings->footer_path) : null;
            $settings->e_stamp_url = $settings->e_stamp_path ? asset('storage/' . $settings->e_stamp_path) : null;
        }
        return response()->json($settings);
    }

    public function publicShow()
    {
        $settings = HotelSetting::first();
        if ($settings) {
            return response()->json([
                'official_name' => $settings->official_name,
                'logo_url' => $settings->logo_path ? asset('storage/' . $settings->logo_path) : null,
            ]);
        }
        return response()->json([
            'official_name' => 'Hotel Management System',
            'logo_url' => null,
        ]);
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
            'e_stamp' => ['nullable','image','max:2048'],
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

        if ($request->hasFile('e_stamp')) {
            if ($settings->e_stamp_path) {
                Storage::disk('public')->delete($settings->e_stamp_path);
            }
            $path = $request->file('e_stamp')->store('e_stamps', 'public');
            $settings->e_stamp_path = $path;
        }

        $settings->fill($data);
        $settings->save();

        return response()->json($settings);
    }

    public function deleteImage(Request $request)
    {
        $request->validate([
            'type' => ['required', 'string', 'in:logo,stamp,header,footer,e_stamp'],
        ]);

        $type = $request->type;
        $settings = HotelSetting::first();

        if (!$settings) {
            return response()->json(['message' => 'Settings not found'], 404);
        }

        $pathField = $type . '_path';
        
        if ($settings->$pathField) {
            Storage::disk('public')->delete($settings->$pathField);
            $settings->$pathField = null;
            $settings->save();
        }

        return response()->json($settings);
    }
}
