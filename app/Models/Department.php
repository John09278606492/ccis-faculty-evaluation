<?php

namespace App\Models;

use App\Traits\NormalizesTextFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Department extends Model
{
    use HasFactory, NormalizesTextFields;

    protected $fillable = [
        'campus_id',
        'name',
        'code',
        'description',
        'is_active',
        'supervisor_id',
        'chairperson_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Department $department) {
            if (strtoupper($department->name) === strtoupper($department->code)) {
                $department->name = strtoupper($department->code);
            }
            foreach (['supervisor_id', 'chairperson_id'] as $field) {
                if (! $department->isDirty($field) && ! $department->isDirty('campus_id')) {
                    continue;
                }
                $id = $department->$field;
                if ($id && ! User::whereKey($id)->where('is_student', false)->whereHas('roles')->whereHas('campuses', fn ($q) => $q->where('campuses.id', $department->campus_id))->exists()) {
                    throw ValidationException::withMessages([$field => 'Choose a staff account belonging to this campus.']);
                }
            }
        });
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function chairperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chairperson_id');
    }

    public function hasEvaluationHead(User $user): bool
    {
        return $this->is_active && ! $user->is_student
            && in_array($user->id, array_filter([$this->supervisor_id, $this->chairperson_id]), true);
    }

    public function additionalSupervisors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_supervisor')->using(DepartmentSupervisor::class)->withTimestamps();
    }

    public function evaluationHeads(): Collection
    {
        return collect([$this->supervisor, $this->chairperson])->filter()->unique('id')->values();
    }

    public function scopeForEvaluationHead(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('supervisor_id', $user->id)->orWhere('chairperson_id', $user->id));
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Get the instructors (users with is_instructor=true) for this department.
     * Uses the User model with instructor_department_id foreign key.
     */
    public function instructors(): HasMany
    {
        return $this->hasMany(User::class, 'instructor_department_id')
            ->where('is_instructor', true);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
