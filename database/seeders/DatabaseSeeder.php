<?php

namespace Database\Seeders;

use App\Models\Campus;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed what a new installation needs: the default roles and one campus to sign in to.
     * CampusSeeder holds sample campuses for development.
     */
    public function run(): void
    {
        $this->call(RolesSeeder::class);

        if (! Campus::query()->exists()) {
            Campus::create(['name' => 'Main Campus', 'slug' => 'main-campus', 'is_active' => true]);
        }
    }
}
