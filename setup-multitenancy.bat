@echo off
REM Multi-Tenancy Setup Script for Windows
REM This script sets up the multi-tenancy system with initial data

echo.
echo 🚀 Starting Multi-Tenancy Setup...
echo.

REM Run migrations
echo 📦 Running migrations...
php artisan migrate

echo ✓ Migrations completed
echo.

REM Seed campuses
echo 🏫 Seeding campuses...
php artisan db:seed --class=CampusSeeder

echo ✓ Campuses seeded
echo.

REM Create roles
echo 🔐 Setting up roles...
php artisan tinker < nul << 'EOF'
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

if (!Role::where('name', 'super_admin')->exists()) {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
}

if (!Role::where('name', 'admin')->exists()) {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
}

if (!Role::where('name', 'user')->exists()) {
    Role::create(['name' => 'user', 'guard_name' => 'web']);
}

exit();
EOF

echo.
echo ✅ Multi-Tenancy Setup Complete!
echo.
echo 📋 Next Steps:
echo 1. Assign super_admin role to your admin user:
echo    php artisan tinker
echo    $user = \App\Models\User::first();
echo    $user->assignRole('super_admin');
echo    exit
echo.
echo 2. Access the admin panel and manage campuses
echo 3. Create users and assign them to campuses
echo.
echo 📚 For more information, see MULTI_TENANCY_GUIDE.md
echo.
pause
