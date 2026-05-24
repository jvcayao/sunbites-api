<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentType;
use App\Models\Concerns\HasBranch;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Traits\HasWallet;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Student extends Model implements Wallet
{
    /** @use HasFactory<StudentFactory> */
    use HasBranch, HasFactory, HasWallet, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'student_number',
        'first_name',
        'last_name',
        'grade_level',
        'section',
        'birthday',
        'photo_path',
        'allergies',
        'notes',
        'qr_code',
        'student_type',
        'enrollment_status',
        'enrollment_date',
        'points',
        'total_spent',
        'credit_balance',
    ];

    protected $recordEvents = ['created', 'updated', 'deleted'];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'enrollment_date' => 'date',
            'student_type' => StudentType::class,
            'enrollment_status' => EnrollmentStatus::class,
            'points' => 'integer',
            'total_spent' => 'decimal:2',
            'credit_balance' => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'last_name',
                'grade_level',
                'section',
                'birthday',
                'student_type',
                'enrollment_status',
                'allergies',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "students.{$eventName}");
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => "{$this->first_name} {$this->last_name}",
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(StudentContact::class);
    }

    public function monthlyPayments(): HasMany
    {
        return $this->hasMany(StudentMonthlyPayment::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(ParentUser::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot(['wallet_alert_threshold', 'linked_at', 'linked_by']);
    }

    public static function generateUniqueQrCode(): string
    {
        $attempts = 0;

        do {
            if (++$attempts > 10) {
                throw new \RuntimeException('Failed to generate a unique QR code after 10 attempts.');
            }

            $code = 'SB-'.Str::random(12);
        } while (static::withoutBranch()->where('qr_code', $code)->exists());

        return $code;
    }
}
