#!/bin/bash

# Multi-Tenancy Setup Script
# This script sets up the multi-tenancy system with initial data

echo "🚀 Starting Multi-Tenancy Setup..."
echo ""

# Run migrations
echo "📦 Running migrations..."
php artisan migrate

echo "✓ Migrations completed"
echo ""

# Seed campuses
echo "🏫 Seeding campuses..."
php artisan db:seed --class=CampusSeeder

echo "✓ Campuses seeded"
echo ""

# Create roles if they don't exist
echo "🔐 Setting up roles..."
php artisan tinker << 'EOF'
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

// Create roles if they don't exist
if (!Role::where('name', 'super_admin')->exists()) {
    Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    echo "✓ Created super_admin role\n";
} else {
    echo "✓ super_admin role already exists\n";
}

if (!Role::where('name', 'admin')->exists()) {
    Role::create(['name' => 'admin', 'guard_name' => 'web']);
    echo "✓ Created admin role\n";
} else {
    echo "✓ admin role already exists\n";
}

if (!Role::where('name', 'user')->exists()) {
    Role::create(['name' => 'user', 'guard_name' => 'web']);
    echo "✓ Created user role\n";
} else {
    echo "✓ user role already exists\n";
}

exit();
EOF

echo ""
echo "✅ Multi-Tenancy Setup Complete!"
echo ""
echo "📋 Next Steps:"
echo "1. Assign super_admin role to your admin user:"
echo "   php artisan tinker"
echo "   \$user = \\App\\Models\\User::first();"
echo "   \$user->assignRole('super_admin');"
echo "   exit"
echo ""
echo "2. Access the admin panel and manage campuses"
echo "3. Create users and assign them to campuses"
echo ""
echo "📚 For more information, see MULTI_TENANCY_GUIDE.md"
