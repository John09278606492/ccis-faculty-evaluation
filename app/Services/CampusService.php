<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

/**
 * Campus Service
 * 
 * Utility service for campus/tenant management operations
 */
class CampusService
{
    /**
     * Get current user's campus
     */
    public static function currentUserCampus(): ?Campus
    {
        return Auth::user()?->campus;
    }

    /**
     * Get current user's campus ID
     */
    public static function currentUserCampusId(): ?int
    {
        return Auth::user()?->campus_id;
    }

    /**
     * Check if current user is super admin
     */
    public static function isSuperAdmin(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    /**
     * Check if current user belongs to a specific campus
     */
    public static function userBelongsToCampus(User $user, Campus $campus): bool
    {
        return $user->campus_id === $campus->id || $user->isSuperAdmin();
    }

    /**
     * Get all accessible campuses for current user
     */
    public static function accessibleCampuses(): Collection
    {
        if (static::isSuperAdmin()) {
            return Campus::where('is_active', true)->get();
        }

        if ($campus = static::currentUserCampus()) {
            return collect([$campus]);
        }

        return collect();
    }

    /**
     * Get users for a specific campus
     */
    public static function getCampusUsers(Campus $campus): Collection
    {
        return User::where('campus_id', $campus->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get users by role in a campus
     */
    public static function getCampusUsersByRole(Campus $campus, string $role): Collection
    {
        return $campus->users()
            ->role($role)
            ->orderBy('name')
            ->get();
    }

    /**
     * Assign user to campus
     */
    public static function assignUserToCampus(User $user, Campus $campus): bool
    {
        return $user->update(['campus_id' => $campus->id]);
    }

    /**
     * Remove user from campus
     */
    public static function removeUserFromCampus(User $user): bool
    {
        return $user->update(['campus_id' => null]);
    }

    /**
     * Get statistics for a campus
     */
    public static function getCampusStats(Campus $campus): array
    {
        return [
            'users_count' => $campus->users()->count(),
            'posts_count' => $campus->posts()->count(),
            'contacts_count' => $campus->contacts()->count(),
            'books_count' => $campus->books()->count(),
            'is_active' => $campus->is_active,
            'created_at' => $campus->created_at,
            'updated_at' => $campus->updated_at,
        ];
    }

    /**
     * Get active campuses
     */
    public static function getActiveCampuses(): Collection
    {
        return Campus::where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get inactive campuses
     */
    public static function getInactiveCampuses(): Collection
    {
        return Campus::where('is_active', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * Toggle campus active status
     */
    public static function toggleCampusStatus(Campus $campus): bool
    {
        return $campus->update(['is_active' => !$campus->is_active]);
    }

    /**
     * Search campuses
     */
    public static function searchCampuses(string $query): Collection
    {
        return Campus::where('name', 'like', "%{$query}%")
            ->orWhere('slug', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->get();
    }

    /**
     * Get campus by slug
     */
    public static function getCampusBySlug(string $slug): ?Campus
    {
        return Campus::where('slug', $slug)->first();
    }

    /**
     * Get paginated campuses
     */
    public static function getPaginatedCampuses(int $perPage = 15)
    {
        return Campus::paginate($perPage);
    }
}
