<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Campus extends Model
{
    // add fillable
    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'phone',
        'email',
        'description',
        'is_active',
    ];

    // add guaded
    protected $guarded = ['id'];

    // add hidden
    protected $hidden = ['created_at', 'updated_at'];

    // add casts
    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Auto format data to uppercase for database normalization
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->formatDataToUppercase();
        });

        static::updating(function ($model) {
            $model->formatDataToUppercase();
        });
    }

    // Format text fields to uppercase
    protected function formatDataToUppercase()
    {
        $uppercaseFields = [
            'name',
            'code',
            'address',
            'city',
            'state',
            'postal_code',
            'country',
        ];

        foreach ($uppercaseFields as $field) {
            if ($this->isDirty($field) && !is_null($this->getAttribute($field))) {
                $this->setAttribute($field, strtoupper($this->getAttribute($field)));
            }
        }

        // Email should be lowercase
        if ($this->isDirty('email') && !is_null($this->getAttribute('email'))) {
            $this->setAttribute('email', strtolower($this->getAttribute('email')));
        }
    }

    /**
     * Get the roles for this campus.
     */
    public function roles()
    {
        return $this->hasMany(\Spatie\Permission\Models\Role::class, 'campus_id');
    }

    /**
     * Get the permissions for this campus.
     */
    public function permissions()
    {
        return $this->hasMany(\Spatie\Permission\Models\Permission::class, 'campus_id');
    }

    /**
     * Get the users assigned to this campus.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }
}
