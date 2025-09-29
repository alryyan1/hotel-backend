<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed default room statuses
        DB::table('room_statuses')->insertOrIgnore([
            ['code' => 'available', 'name' => 'Available', 'color' => '#22c55e', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'occupied', 'name' => 'Occupied', 'color' => '#ef4444', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'cleaning', 'name' => 'Cleaning', 'color' => '#eab308', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'maintenance', 'name' => 'Maintenance', 'color' => '#3b82f6', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed default roles
        DB::table('roles')->insertOrIgnore([
            ['name' => 'admin', 'display_name' => 'Administrator', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'reception', 'display_name' => 'Receptionist', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'housekeeping', 'display_name' => 'Housekeeping', 'description' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed default admin user
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        // Attach admin role to admin user
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminRoleId) {
            DB::table('role_user')->updateOrInsert(
                ['role_id' => $adminRoleId, 'user_id' => $admin->id],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
