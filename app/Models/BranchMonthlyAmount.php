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
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'school_month' => SchoolMonth::class,
            'amount' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
