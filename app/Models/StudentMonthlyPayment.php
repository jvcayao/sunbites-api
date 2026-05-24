<?php

namespace App\Models;

use App\Enums\SchoolMonth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentMonthlyPayment extends Model
{
    protected $fillable = [
        'student_id',
        'school_month',
        'year',
        'status',
        'amount',
        'recorded_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'year' => 'integer',
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
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

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
