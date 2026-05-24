<?php

namespace App\Models;

use App\Enums\SchoolMonth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchMonthlyAmount extends Model
{
    protected $fillable = [
        'branch_id',
        'school_month',
        'year',
        'days',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'year' => 'integer',
            'days' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Resolve the monthly amount for a branch, school month, and year.
     * Falls back to days × daily_meal_rate from system configuration when no
     * branch-level override exists.
     */
    public static function resolveAmount(int $branchId, SchoolMonth $month, int $year): float
    {
        $record = static::where('branch_id', $branchId)
            ->where('school_month', $month->value)
            ->where('year', $year)
            ->first();

        if ($record) {
            return (float) $record->amount;
        }

        $days = config("sunbites.school_months.{$month->value}.days", 0);

        return (float) ($days * SystemConfiguration::getValue('daily_meal_rate', 135));
    }
}
