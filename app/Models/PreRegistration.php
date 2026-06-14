<?php

namespace App\Models;

use App\Enums\PreRegistrationStatus;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreRegistration extends Model
{
    use HasBranch, HasFactory;

    protected $fillable = [
        'branch_id',
        'first_name',
        'last_name',
        'student_number',
        'grade_level',
        'section',
        'birthday',
        'allergies',
        'notes',
        'enrollment_type',
        'subscription_start_month',
        'subscription_start_year',
        'subscription_end_month',
        'subscription_end_year',
        'signatory_name',
        'acknowledged_at',
        'status',
        'approved_by',
        'rejected_by',
        'rejection_reason',
        'processed_at',
        'recaptcha_score',
        'submitter_ip',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PreRegistrationStatus::class,
            'birthday' => 'date',
            'acknowledged_at' => 'datetime',
            'processed_at' => 'datetime',
            'expires_at' => 'datetime',
            'recaptcha_score' => 'decimal:2',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PreRegistrationContact::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * @return array{
     *     branch_id: int,
     *     student_number: string|null,
     *     first_name: string,
     *     last_name: string,
     *     grade_level: string,
     *     section: string|null,
     *     birthday: string,
     *     student_type: string,
     *     photo_path: null,
     *     allergies: string|null,
     *     notes: string|null,
     *     subscription_start_month: string|null,
     *     subscription_start_year: int|null,
     *     subscription_end_month: string|null,
     *     subscription_end_year: int|null,
     * }
     */
    public function toEnrollmentData(): array
    {
        return [
            'branch_id' => $this->branch_id,
            'student_number' => $this->student_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'grade_level' => $this->grade_level,
            'section' => $this->section,
            'birthday' => $this->birthday->toDateString(),
            'student_type' => $this->enrollment_type,
            'photo_path' => null,
            'allergies' => $this->allergies,
            'notes' => $this->notes,
            'subscription_start_month' => $this->subscription_start_month,
            'subscription_start_year' => $this->subscription_start_year,
            'subscription_end_month' => $this->subscription_end_month,
            'subscription_end_year' => $this->subscription_end_year,
        ];
    }
}
