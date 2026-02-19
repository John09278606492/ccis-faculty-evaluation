# Filament Multi-Tenancy (Campus Isolation) Implementation

## Overview

This Laravel + Filament project implements a robust **many-to-many multi-tenancy system** using Filament's native tenancy features. Users can belong to multiple campuses (tenants), and each user can switch between their assigned campuses. Super admins can manage all campuses and their members.

## Architecture

### Key Filament Components

1. **User Model** (`app/Models/User.php`)
   - Implements `Filament\Models\Contracts\HasTenants`
   - Many-to-many relationship with Campus through `campus_user` pivot table
   - Methods:
     - `campuses()` - Get all campuses user belongs to
     - `getTenants()` - Required by HasTenants interface
     - `canAccessTenant()` - Validates tenant access

2. **Campus Model** (`app/Models/Campus.php`)
   - Implements `HasName` and `HasAvatar` Filament contracts
   - Relationships:
     - `users()` - Many-to-many with User
     - `posts()`, `contacts()`, `books()` - Content ownership

3. **Filament Tenant Pages**
   - `RegisterCampus` - Extends `Filament\Pages\Tenancy\RegisterTenant`
   - `EditCampusProfile` - Extends `Filament\Pages\Tenancy\EditTenantProfile`

4. **Tenant Middleware** (`ApplyTenantScopes`)
   - Automatically applies global scopes to models
   - Scopes queries to current tenant context

### Database Schema

#### Campus-User Pivot Table
```sql
campus_user
├── id (primary key)
├── campus_id (foreign key → campuses)
├── user_id (foreign key → users)
├── created_at
└── updated_at
```

#### Campuses Table
```sql
campuses
├── id
├── name (unique)
├── slug (unique)
├── description
├── email, phone, website
├── address, city, state, postal_code, country
├── logo_url
├── is_active
└── timestamps
```

#### Content Tables (posts, contacts, books)
- Connected to Campus through Filament's tenant relationship
- Campus context is injected automatically by Filament

## How It Works

### Tenant Switching Flow
```
User Login
    ↓
Filament checks getTenants()
    ↓
List all campuses user belongs to
    ↓
User selects a campus from dropdown menu
    ↓
URL changes to /admin/{campus-slug}/...
    ↓
Resources automatically scoped to selected campus
    ↓
Only data for that campus is visible
```

### Access Control
- **Super Admin**: Can access all campuses and manage campus settings
- **Regular User**: Can only access campuses they're assigned to
- **Tenant Validation**: `canAccessTenant()` prevents unauthorized access

## Setup Instructions

### 1. Run Migrations
```bash
php artisan migrate
```

Creates:
- `campuses` table
- `campus_user` pivot table
- Cleans up old single-tenant columns

### 2. Seed Sample Campuses
```bash
php artisan db:seed --class=CampusSeeder
```

Creates 3 sample campuses:
- Main Campus
- Secondary Campus
- Training Campus

### 3. Create Roles
```bash
php artisan tinker
> use Spatie\Permission\Models\Role;
> Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
> exit
```

### 4. Create Super Admin User and Assign Campus
```bash
php artisan tinker
> $user = \App\Models\User::first();
> $user->assignRole('super_admin');
> $campus = \App\Models\Campus::first();
> $user->campuses()->attach($campus);
> exit
```

## Usage

### In Resources

Resources are automatically scoped to the current tenant:

```php
// Post resource - automatically scoped to current campus
class PostResource extends Resource
{
    protected static ?string $model = Post::class;
    
    // Optionally define ownership relationship
    protected static ?string $tenantOwnershipRelationshipName = 'campus';
}
```

### Creating Records

Records are automatically associated with the current campus:

```php
// User creates a post within a campus context
$post = Post::create([
    'title' => 'New Post',
    'content' => 'Content here'
    // campus_id is automatically set by Filament
]);
```

### Querying Data

```php
// In a tenant-aware context, queries are automatically scoped
$posts = Post::all(); // Only posts from current campus

// Outside tenant context, use explicit relationships
$posts = Campus::find(1)->posts()->get();
```

### Accessing Current Tenant

```php
use Filament\Facades\Filament;

$campus = Filament::getTenant(); // Get current tenant
$campusId = Filament::getTenant()?->id;
$campusName = Filament::getTenant()?->name;
```

## Filament Panel Configuration

Located in `app/Providers/Filament/AdminPanelProvider.php`:

```php
public function panel(Panel $panel): Panel
{
    return $panel
        ->tenant(Campus::class, slugAttribute: 'slug')
        ->tenantRegistration(RegisterCampus::class)
        ->tenantProfile(EditCampusProfile::class)
        ->tenantMiddleware([
            ApplyTenantScopes::class,
        ], isPersistent: true)
        // ... other config
    ;
}
```

## URL Structure

With slug-based routing:
```
/admin/main-campus/posts
/admin/main-campus/contacts
/admin/secondary-campus/books
```

Users can switch campuses using the tenant dropdown in the panel header.

## Security Features

✅ **Automatic Tenant Validation** - `canAccessTenant()` prevents unauthorized access
✅ **User/Tenant Mapping** - Only registered campus members can access
✅ **Automatic Query Scoping** - Global scopes filter by tenant
✅ **Admin Tenant Management** - Super admins can manage all campuses
✅ **Middleware Enforcement** - Tenant context applied persistently

## API Integration with Tenancy

When using API routes with tenants:

```php
// Apply tenant to API resources
->tenantRegistration(RegisterCampus::class)
->tenantProfile(EditCampusProfile::class)
```

## Tenant Menu Customization

The tenant switcher in the panel header is automatically configured:
- Shows list of user's campuses
- Dropdown to switch tenants
- Register new campus link (if enabled)
- Edit campus profile link

## Advanced: Disabling Tenancy for Resources

Some resources may need to be shared across all tenants:

```php
class GlobalResource extends Resource
{
    protected static bool $isScopedToTenant = false;
    // This resource is accessible to all users
}
```

Or disable tenancy globally and opt-in per resource:

```php
// In service provider
Resource::scopeToTenant(false);

// In specific resource
protected static bool $isScopedToTenant = true;
```

## Multiple Tenant Domains

For subdomain-based routing:

```php
->tenantDomain('{tenant:slug}.example.com')
```

Users access campuses via subdomains instead of URL prefixes.

## Troubleshooting

### User sees "Not registered" error
- User must be added to campus via `campuses()->attach()`
- Check `campus_user` pivot table

### Campus switching not working
- Verify `getTenants()` returns correct collection
- Check middleware is registered in panel config
- Ensure user has `canAccessTenant()` permission

### Tenant context not available in queries
- Verify `ApplyTenantScopes` middleware is registered
- Check `isPersistent: true` flag is set
- Ensure model has `campus` relationship

### Super admin cannot access all campuses
- Verify `isSuperAdmin()` method works correctly
- Check role assignment: `$user->assignRole('super_admin')`
- Ensure `canAccessTenant()` includes super admin check

## Best Practices

1. **Always use tenant relationships** - Let Filament handle scoping
2. **Implement canAccessTenant()** - Validate all tenant access
3. **Use ApplyTenantScopes middleware** - Ensures automatic scoping
4. **Test across tenants** - Create test data in multiple campuses
5. **Audit permissions** - Use FilamentShield for fine-grained control

## Migrating to Many-to-Many Tenancy

If you were using single-tenant (campus_id) approach:

1. Create `campus_user` pivot table
2. Migrate existing `campus_id` values to pivot table
3. Drop `campus_id` columns from content tables
4. Update models to use many-to-many relationships
5. Update panel configuration with tenancy setup

## Further Reading

- [Filament Multi-tenancy Documentation](https://filamentphp.com/docs/3.x/panels/tenancy)
- [Laravel Relationships Documentation](https://laravel.com/docs/eloquent-relationships)
- [Spatie Permission Package](https://spatie.be/docs/laravel-permission)

