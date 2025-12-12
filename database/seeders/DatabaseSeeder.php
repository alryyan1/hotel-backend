<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Floor;
use App\Models\Room;
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
            ['username' => 'admin'],
            [
                'name' => 'Admin',
                'username' => 'admin',
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

        // Seed basic room types
        DB::table('room_types')->insertOrIgnore([
            [
                'code' => 'SGL',
                'name' => 'Single',
                'capacity' => 1,
                'base_price' => 150.00,
                'description' => 'غرفة مفردة مناسبة لنزيل واحد',
                'area' => 18,
                'beds_count' => 1,
                'amenities' => json_encode(['تكييف', 'واي فاي', 'تلفاز']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'DBL',
                'name' => 'Double',
                'capacity' => 2,
                'base_price' => 220.00,
                'description' => 'غرفة مزدوجة لشخصين',
                'area' => 24,
                'beds_count' => 1,
                'amenities' => json_encode(['تكييف', 'واي فاي', 'ثلاجة صغيرة', 'تلفاز']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'STE',
                'name' => 'Suite',
                'capacity' => 3,
                'base_price' => 400.00,
                'description' => 'جناح واسع مع غرفة معيشة',
                'area' => 45,
                'beds_count' => 2,
                'amenities' => json_encode(['تكييف', 'واي فاي', 'ثلاجة', 'شرفة', 'منطقة جلوس']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'DLX',
                'name' => 'VIP / Deluxe',
                'capacity' => 2,
                'base_price' => 550.00,
                'description' => 'غرفة ديلوكس فاخرة',
                'area' => 35,
                'beds_count' => 1,
                'amenities' => json_encode(['تكييف', 'واي فاي', 'شرفة', 'آلة قهوة', 'ميني بار']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Seed floors and rooms
        $this->seedFloorsAndRooms();
    }

    /**
     * Seed floors and rooms
     */
    private function seedFloorsAndRooms(): void
    {
        // Get the first room type and room status for default assignment
        $defaultRoomType = DB::table('room_types')->first();
        $defaultRoomStatus = DB::table('room_statuses')->first();

        if (!$defaultRoomType || !$defaultRoomStatus) {
            throw new \Exception('Room types and room statuses must be seeded first');
        }

        // Create 4 floors
        for ($floorNumber = 1; $floorNumber <= 4; $floorNumber++) {
            $floor = Floor::updateOrCreate(
                ['number' => $floorNumber],
                [
                    'name' => "الطابق {$floorNumber}",
                    'description' => null,
                ]
            );

            // Determine number of rooms for this floor
            // Floors 1-3 have 2 rooms each, floor 4 has 1 room
            $roomsPerFloor = ($floorNumber === 4) ? 1 : 2;

            // Create rooms for this floor
            for ($roomIndex = 1; $roomIndex <= $roomsPerFloor; $roomIndex++) {
                $roomNumber = ($floorNumber * 100) + $roomIndex; // 101, 102, 201, 202, etc.

                Room::updateOrCreate(
                    ['number' => (string)$roomNumber],
                    [
                        'floor_id' => $floor->id,
                        'room_type_id' => $defaultRoomType->id,
                        'room_status_id' => $defaultRoomStatus->id,
                        'beds' => 1,
                        'notes' => null,
                    ]
                );
            }
        }
    }
}
