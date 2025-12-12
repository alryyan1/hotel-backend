<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HotelSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class HotelSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = HotelSetting::firstOrNew([]);
        
        // Set default hotel information
        $settings->official_name = 'نوفا للشقق المفروشة';
        $settings->address_line = 'امدرمان شارع عبدالمنعم السيسي جنوب مستشفى مكة للعيون';
        $settings->phone = '0912324131';
        $settings->phone_2 = '0120243000';
        $settings->email = 'novafurnished@gmail.com';
        
        // Check if nova_stamp.png exists in public folder
        $stampSourcePath = public_path('nova_stamp.png');
        
        if (File::exists($stampSourcePath)) {
            // Ensure stamps directory exists
            $stampsDir = storage_path('app/public/stamps');
            if (!File::exists($stampsDir)) {
                File::makeDirectory($stampsDir, 0755, true);
            }
            
            // Copy file to storage
            $destinationPath = 'stamps/nova_stamp.png';
            $fullDestinationPath = storage_path('app/public/' . $destinationPath);
            
            // Delete old stamp if exists
            if ($settings->stamp_path && Storage::disk('public')->exists($settings->stamp_path)) {
                Storage::disk('public')->delete($settings->stamp_path);
            }
            
            // Copy the file
            File::copy($stampSourcePath, $fullDestinationPath);
            
            // Set stamp_path in settings
            $settings->stamp_path = $destinationPath;
            
            $this->command->info('Default stamp (nova_stamp.png) has been set successfully!');
        } else {
            $this->command->warn('nova_stamp.png not found in public folder. Skipping stamp setup.');
        }

        // Check if header.png exists in public folder
        $headerSourcePath = public_path('header.png');
        
        if (File::exists($headerSourcePath)) {
            // Ensure headers directory exists
            $headersDir = storage_path('app/public/headers');
            if (!File::exists($headersDir)) {
                File::makeDirectory($headersDir, 0755, true);
            }
            
            // Copy file to storage
            $destinationPath = 'headers/header.png';
            $fullDestinationPath = storage_path('app/public/' . $destinationPath);
            
            // Delete old header if exists
            if ($settings->header_path && Storage::disk('public')->exists($settings->header_path)) {
                Storage::disk('public')->delete($settings->header_path);
            }
            
            // Copy the file
            File::copy($headerSourcePath, $fullDestinationPath);
            
            // Set header_path in settings
            $settings->header_path = $destinationPath;
            
            $this->command->info('Default header (header.png) has been set successfully!');
        } else {
            $this->command->warn('header.png not found in public folder. Skipping header setup.');
        }

        // Check if footer.png exists in public folder
        $footerSourcePath = public_path('footer.png');
        
        if (File::exists($footerSourcePath)) {
            // Ensure footers directory exists
            $footersDir = storage_path('app/public/footers');
            if (!File::exists($footersDir)) {
                File::makeDirectory($footersDir, 0755, true);
            }
            
            // Copy file to storage
            $destinationPath = 'footers/footer.png';
            $fullDestinationPath = storage_path('app/public/' . $destinationPath);
            
            // Delete old footer if exists
            if ($settings->footer_path && Storage::disk('public')->exists($settings->footer_path)) {
                Storage::disk('public')->delete($settings->footer_path);
            }
            
            // Copy the file
            File::copy($footerSourcePath, $fullDestinationPath);
            
            // Set footer_path in settings
            $settings->footer_path = $destinationPath;
            
            $this->command->info('Default footer (footer.png) has been set successfully!');
        } else {
            $this->command->warn('footer.png not found in public folder. Skipping footer setup.');
        }
        
        // Save all settings
        $settings->save();
        
        $this->command->info('Hotel settings have been seeded successfully!');
    }
}
