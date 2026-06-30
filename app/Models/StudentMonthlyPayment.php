<?php

namespace App\Models;

use App\Enums\SchoolMonth;
use Database\Factories\StudentMonthlyPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMonthlyPayment extends Model
{
    /** @use HasFactory<StudentMonthlyPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'school_month',
        'year',
        'status',
        'amount',
        'recorded_at',
        'recorded_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'year' => 'integer',
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
