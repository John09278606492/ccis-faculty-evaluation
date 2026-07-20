<?php

namespace Database\Seeders;

use App\Models\Campus;
use Illuminate\Database\Seeder;

class CampusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default campuses for testing
        $campuses = [
            [
                'name' => 'Main Campus',
                'slug' => 'main-campus',
                'description' => 'Main administrative campus',
                'email' => 'main@campus.local',
                'phone' => '+1-555-0001',
                'address' => '123 Main Street',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'United States',
                'is_active' => true,
            ],
            [
                'name' => 'Secondary Campus',
                'slug' => 'secondary-campus',
                'description' => 'Secondary educational campus',
                'email' => 'secondary@campus.local',
                'phone' => '+1-555-0002',
                'address' => '456 Oak Avenue',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'United States',
                'is_active' => true,
            ],
            [
                'name' => 'Training Campus',
                'slug' => 'training-campus',
                'description' => 'Staff training and development center',
                'email' => 'training@campus.local',
                'phone' => '+1-555-0003',
                'address' => '789 Pine Road',
                'city' => 'Chicago',
                'state' => 'IL',
                'postal_code' => '60601',
                'country' => 'United States',
                'is_active' => true,
            ],
        ];

        foreach ($campuses as $campus) {
            Campus::firstOrCreate(['slug' => $campus['slug']], $campus);
        }

        echo "✓ Campus seeding completed\n";
    }
}
