<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Campus;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Run ShieldSeeder first to create roles and permissions
        $this->call(ShieldSeeder::class);

        // Create default campus
        $defaultCampus = Campus::firstOrCreate([
            'code' => 'HQ',
        ], [
            'name' => 'HEADQUARTERS',
            'address' => 'Main Office',
            'city' => 'Default',
            'country' => 'Default',
            'is_active' => true,
        ]);

        // Create ONLY ONE super admin user with NO campus restriction
        $superAdmin = User::firstOrCreate([
            'email' => 'super_admin@admin.com',
        ], [
            'name' => 'Super Admin',
            'password' => bcrypt('password'),
            'campus_id' => null,  // Super admin has NO campus restriction - can access all campuses
            'email_verified_at' => now(),
        ]);

        // Assign super_admin role
        if ($superAdmin->getRoleNames()->isEmpty()) {
            $superAdmin->assignRole('super_admin');
        }

        // Create campus-specific admin users (optional - for testing)
        // These admins are tied to their campus
        $campusAdmin = User::firstOrCreate([
            'email' => 'admin@admin.com',
        ], [
            'name' => 'Campus Admin',
            'password' => bcrypt('password'),
            'campus_id' => $defaultCampus->id,  // Tied to HQ campus only
            'email_verified_at' => now(),
        ]);

        // Assign admin role (not super_admin) to campus admin
        if ($campusAdmin->getRoleNames()->isEmpty()) {
            $campusAdmin->assignRole('Moderator');  // Or create a campus-specific admin role
        }

        // Create other test users
        User::factory(10)->create([
            'campus_id' => $defaultCampus->id,
        ]);

        //call other seeders
        $this->call(
            [
                BookSeeder::class,
                PostSeeder::class,
                ContactSeeder::class,
            ]
        );
    }
}
