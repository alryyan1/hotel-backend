<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        for ($i = 0; $i < 50; $i++) {
            Customer::create([
                'name' => $faker->name(),
                'phone' => $faker->phoneNumber(),
                'national_id' => $faker->unique()->numerify('##########'), // 10-digit national ID
                'address' => $faker->address(),
                'date_of_birth' => $faker->date('Y-m-d', '-18 years'), // At least 18 years old
                'gender' => $faker->randomElement(['male', 'female']),
                'document_path' => null, // Can be set later if needed
            ]);
        }
    }
}




