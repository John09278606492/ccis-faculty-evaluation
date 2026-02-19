# Filament Multi-Tenancy - Quick Reference

## Installation Complete ✅

Your project now has a complete Filament-based multi-tenancy system using many-to-many relationships (Users belong to multiple Campuses).

## Files Created/Modified

### New Files
- `app/Models/Campus.php` - Tenant model
- `app/Http/Middleware/ApplyTenantScopes.php` - Auto-scoping middleware
- `app/Filament/Pages/Tenancy/RegisterCampus.php` - Tenant registration
- `app/Filament/Pages/Tenancy/EditCampusProfile.php` - Tenant profile editing
- Database migrations for campus_user pivot table

### Modified Files
- `app/Models/User.php` - Implements HasTenants
- `app/Providers/Filament/AdminPanelProvider.php` - Tenancy config
- `bootstrap/app.php` - Middleware registration

## Getting Started

### 1. Create Super Admin
```bash
php artisan tinker
$user = \App\Models\User::first();
$user->assignRole('super_admin');
$campus = \App\Models\Campus::first();
$user->campuses()->attach($campus->id);
exit
```

### 2. Access Admin Panel
```
URL: http://localhost/admin
Username: (your user email)
Password: (your user password)
```

You should see a tenant dropdown in the top navigation showing your campuses.

### 3. Switch Between Campuses
Click the tenant switcher dropdown to change campuses. All data will be filtered automatically.

## Key Concepts

### What is a Tenant?
A "tenant" is a campus. Each user can belong to multiple campuses.

### How Data is Isolated
When you create a post while viewing "Main Campus", it's automatically associated with that campus. Users can only see data for campuses they belong to.

### Super Admin Access
Users with the `super_admin` role can access all campuses and manage campus settings.

## Common Tasks

### Add User to Campus
```php
$user = \App\Models\User::find(1);
$campus = \App\Models\Campus::find(1);
$user->campuses()->attach($campus->id);
```

### Remove User from Campus
```php
$user = \App\Models\User::find(1);
$user->campuses()->detach(1);
```

### Get User's Campuses
```php
$user = \App\Models\User::find(1);
$campuses = $user->campuses; // Collection of all campuses user belongs to
```

### Get Campus Users
```php
$campus = \App\Models\Campus::find(1);
$users = $campus->users; // Collection of all users in campus
```

### Check if User Can Access Campus
```php
$user = \App\Models\User::find(1);
$user->canAccessTenant($campus); // Returns true/false
```

### Access Current Tenant
```php
use Filament\Facades\Filament;

$campus = Filament::getTenant(); // Current campus
```

## URL Structure

```
/admin                           - Login
/admin/main-campus              - Main Campus tenant
/admin/main-campus/posts        - Posts in Main Campus
/admin/main-campus/contacts     - Contacts in Main Campus
/admin/secondary-campus         - Switch to Secondary Campus
/admin/secondary-campus/books   - Books in Secondary Campus
```

## Database Tables

```
campuses              - Tenant/campus data
campus_user          - User-campus relationships (pivot table)
users                - User data
posts                - Content (automatically scoped by campus)
contacts             - Content (automatically scoped by campus)
books                - Content (automatically scoped by campus)
```

## Documentation Files

- `FILAMENT_TENANCY_SETUP.md` - Complete setup guide
- `MULTI_TENANCY_GUIDE.md` - Detailed implementation guide
- `MULTITENANCY_SUMMARY.md` - Original summary

## Troubleshooting

**Q: Tenant dropdown not showing**
A: Ensure user is attached to at least one campus:
```bash
php artisan tinker
$user->campuses()->attach($campus_id);
```

**Q: Can't switch campuses**
A: Check that user has `canAccessTenant()` permission for that campus.

**Q: All users seeing all data**
A: Verify `ApplyTenantScopes` middleware is registered in `AdminPanelProvider`.

**Q: Super admin can't access all campuses**
A: Check that super_admin role is assigned and `isSuperAdmin()` returns true.

## Next Steps

1. Create additional campuses via the admin panel
2. Add users to campuses
3. Test that data is properly isolated
4. Create content (posts, contacts, books) in different campuses
5. Verify users only see data for their campuses
6. Test super admin can access everything

## Security Notes

✅ Automatic tenant validation prevents unauthorized access
✅ Queries are automatically scoped to current tenant
✅ Data is isolated between campuses
✅ Super admins must be explicitly assigned the role
✅ Users see a dropdown to switch between their campuses

## API Routes

If using API routes, ensure they're properly configured:
```php
// In routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/posts', [PostController::class, 'index']);
    // Routes will be automatically scoped to user's current tenant
});
```

## Performance Tips

1. Eager load relationships:
```php
$posts = Post::with('campus')->get();
```

2. Use pagination:
```php
$posts = Post::paginate(15);
```

3. Index the campus foreign key columns for faster queries

## Support

For detailed documentation, see:
- `FILAMENT_TENANCY_SETUP.md` - Comprehensive setup guide
- [Filament Docs](https://filamentphp.com/docs/3.x/panels/tenancy)
- [Laravel Docs](https://laravel.com/docs)
