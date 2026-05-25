<?php

namespace App\Models;

use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDailyStatus extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'date',
        'status',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => StaffStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
