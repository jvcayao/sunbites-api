<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParentPaymentReminder extends Model
{
    protected $fillable = [
        'parent_user_id',
        'branch_id',
        'school_month',
        'school_year',
        'sent_at',
        'sent_by_user_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(ParentUser::class, 'parent_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
