<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class ParentUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'parents';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'address',
        'profile_photo_path',
        'email_verified_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActivated(): bool
    {
        return $this->email_verified_at !== null;
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => "{$this->first_name} {$this->last_name}",
        );
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile_photo_path
                ? Storage::url($this->profile_photo_path)
                : null,
        );
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot(['wallet_alert_threshold', 'linked_at', 'linked_by']);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'parent_id');
    }
}
