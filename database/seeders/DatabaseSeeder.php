<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ]
        );

        if (!Schema::hasTable('homes') || DB::table('homes')->where('user_id', $user->id)->exists()) {
            return;
        }

        $homeId = DB::table('homes')->insertGetId([
            'user_id' => $user->id,
            'name' => 'Toronto Townhouse',
            'property_type' => 'townhouse',
            'city_region' => 'Toronto, ON',
            'purchase_date' => '2026-06-05',
            'purchase_price' => 425000,
            'square_footage' => 700,
            'currency' => 'CAD',
            'mortgage_payment' => 1985,
            'hoa_fee' => 727,
            'property_tax' => 220,
            'occupancy_status' => 'owner_occupied',
            'primary_use' => 'primary_residence',
            'is_primary' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('rooms')) {
            foreach ([
                ['Living room', 'living'],
                ['Kitchen', 'kitchen'],
                ['Primary bedroom', 'bedroom'],
                ['Office / studio', 'office'],
                ['Bathroom', 'bathroom'],
                ['Balcony / exterior', 'exterior'],
            ] as $index => [$name, $type]) {
                DB::table('rooms')->insert([
                    'user_id' => $user->id,
                    'home_id' => $homeId,
                    'name' => $name,
                    'room_type' => $type,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
