<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'nickname',
        'birthday',
        'gender',
        'civil_status',
        'profile_photo_path',
        'phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'address_line',
        'city',
        'province',
        'zip_code',
        'position',
        'employment_type',
        'date_hired',
        'daily_rate',
        'sss_number',
        'pagibig_number',
        'philhealth_number',
        'tin_number',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'sss_number',
        'pagibig_number',
        'philhealth_number',
        'tin_number',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birthday' => 'date',
            'date_hired' => 'date',
            'daily_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Spatie Permission uses getDefaultGuardName() to resolve which guard's roles
     * and permissions to look up. When requests are authenticated via Sanctum,
     * Laravel's auth manager calls shouldUse('sanctum'), which changes the global
     * default guard. Without this override, Spatie would look for roles/permissions
     * with guard_name='sanctum' and find nothing (they are seeded as 'web').
     */
    public function getDefaultGuardName(): string
    {
        return 'web';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'email', 'is_active', 'position'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->first_name} {$this->last_name}"),
        );
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user')
            ->withPivot(['assigned_at', 'assigned_by']);
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return "staff.{$this->id}";
    }

    public function staffDailyStatuses(): HasMany
    {
        return $this->hasMany(StaffDailyStatus::class);
    }
}
