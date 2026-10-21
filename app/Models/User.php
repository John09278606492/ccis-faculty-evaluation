<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Traits\NormalizesTextFields;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Jeffgreco13\FilamentBreezy\Traits\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, NormalizesTextFields, Notifiable, TwoFactorAuthenticatable;

    /**
     * Boot the model.
     * Auto-generate the name field from first_name, middle_name, and last_name.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (User $user) {
            // Normalize text fields first
            $user->normalizeTextFields();

            // Then generate the full name
            if ($user->first_name && $user->last_name) {
                $middleName = $user->middle_name ? " {$user->middle_name} " : ' ';
                $user->name = trim("{$user->first_name}{$middleName}{$user->last_name}");
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        // Name fields (for all accounts)
        'first_name',
        'middle_name',
        'last_name',
        // Student profile fields
        'student_id',
        'year_level',
        'is_student',
        // Instructor profile fields
        'is_instructor',
        'instructor_department_id',
        'employee_id',
        'position',
        'phone',
        'widget_order',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'pin_issued_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
            'is_student' => 'boolean',
            'is_instructor' => 'boolean',
            'widget_order' => 'array',
        ];
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar_url ? Storage::url($this->avatar_url) : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Only allow users with at least one assigned role to access the admin panel.
        // This prevents SSO-created users (who have no role) from accessing admin features.
        return $this->roles()->exists();
    }

    /**
     * Get the campuses (tenants) that this user belongs to.
     */
    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'campus_user')
            ->withPivot('created_at', 'updated_at');
    }

    /**
     * Filament: Get tenants for the user (for multi-tenancy).
     */
    public function getTenants(Panel $panel): Collection
    {
        // Super admins can see all campuses
        if ($this->isSuperAdmin()) {
            return Campus::all();
        }

        return $this->campuses;
    }

    /**
     * Filament: Check if user can access a specific tenant.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        // Super admins can access any campus
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->campuses()->whereKey($tenant)->exists();
    }

    /**
     * Check if user is super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Check if this user is a student.
     */
    public function isStudent(): bool
    {
        return $this->is_student || $this->hasRole('student');
    }

    /**
     * Get the full name (first + middle + last, or fallback to name).
     */
    public function getFullNameAttribute(): string
    {
        if ($this->first_name && $this->last_name) {
            $middleName = $this->middle_name ? " {$this->middle_name} " : ' ';

            return "{$this->first_name}{$middleName}{$this->last_name}";
        }

        return $this->name;
    }

    /**
     * Get the course offerings this student is enrolled in.
     */
    public function courseOfferings(): BelongsToMany
    {
        return $this->belongsToMany(CourseOffering::class, 'course_offering_student', 'user_id', 'course_offering_id')
            ->using(ClassroomEnrollment::class)
            ->withPivot('enrolled_at')
            ->withTimestamps();
    }

    /**
     * Alias for courseOfferings - used in bulk attach filtering.
     */
    public function enrolledCourseOfferings(): BelongsToMany
    {
        return $this->courseOfferings();
    }

    /**
     * Get the ratings submitted by this student.
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class, 'user_id');
    }

    /**
     * Get this student's quiz attempts.
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'user_id');
    }

    /**
     * Quizzes this student is explicitly assigned (only meaningful for quizzes
     * with assign_to_all = false — see Quiz::isAssignedTo()).
     */
    public function assignedQuizzes(): BelongsToMany
    {
        return $this->belongsToMany(Quiz::class, 'quiz_student')->using(QuizAssignment::class);
    }

    /**
     * Check if this user is an instructor.
     */
    public function isInstructor(): bool
    {
        // A user created in this request does not carry the column yet, so the
        // cast can be null until it is read back from the database.
        return (bool) $this->is_instructor;
    }

    /**
     * Get the instructor's department.
     */
    public function instructorDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'instructor_department_id');
    }

    /**
     * Get the course offerings where this user is the instructor.
     */
    public function courseOfferingsAsInstructor(): HasMany
    {
        return $this->hasMany(CourseOffering::class, 'user_id');
    }

    /**
     * Alias: course offerings where this user is the evaluatee.
     */
    public function courseOfferingsAsEvaluatee(): HasMany
    {
        return $this->courseOfferingsAsInstructor();
    }

    /**
     * Alias: evaluatee's department (same as instructorDepartment).
     */
    public function evaluateeDepartment(): BelongsTo
    {
        return $this->instructorDepartment();
    }
}
