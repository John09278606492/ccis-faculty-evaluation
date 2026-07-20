<?php

namespace App\Models;

use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use App\Traits\NormalizesTextFields;

class Campus extends Model implements HasName, HasAvatar
{
    /** @use HasFactory<\Database\Factories\CampusFactory> */
    use HasFactory, NormalizesTextFields;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'logo_url',
        'website',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = ['created_at', 'updated_at'];

    protected static function boot(): void
    {
        parent::boot();

        // Auto-attach all super_admin users when a new campus is created
        static::created(function (Campus $campus) {
            $superAdmins = User::role('super_admin')->pluck('id');
            if ($superAdmins->isNotEmpty()) {
                $campus->users()->syncWithoutDetaching($superAdmins);
            }
        });
    }

    /**
     * Get the users (members) for this campus.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'campus_user')
            ->withPivot('created_at', 'updated_at');
    }

    /**
     * Get the posts for this campus.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get the contacts for this campus.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Get the books for this campus.
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /**
     * Get the departments for this campus.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /**
     * Get the school years for this campus.
     */
    public function schoolYears(): HasMany
    {
        return $this->hasMany(SchoolYear::class);
    }

    /**
     * Get the students (users with is_student=true) for this campus through the pivot table.
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'campus_user')
            ->where('users.is_student', true);
    }

    /**
     * Get the rating criteria for this campus.
     */
    public function ratingCriteria(): HasMany
    {
        return $this->hasMany(RatingCriteria::class);
    }

    /**
     * Get the forms assigned to this campus.
     */
    public function forms(): BelongsToMany
    {
        return $this->belongsToMany(Form::class, 'campus_form')
            ->withTimestamps();
    }

    /**
     * Filament: Get the name attribute for display.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * Filament: Get avatar URL for the campus.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logo_url ? Storage::url($this->logo_url) : null;
    }
}


