<?php

namespace App\Models;

use Database\Factories\ParentUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class ParentUser extends Authenticatable
{
    /** @use HasFactory<ParentUserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

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
        'disabled_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActivated(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isAccessible(): bool
    {
        return ! $this->isDisabled() && $this->isActivated();
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

    public function receivesBroadcastNotificationsOn(): string
    {
        return "parents.{$this->id}";
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'parent_id');
    }
}
