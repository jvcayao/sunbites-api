<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'parent_user_id',
    'branch_id',
    'school_month',
    'school_year',
    'sent_at',
    'sent_by_user_id',
    'send_count',
])]
class ParentPaymentReminder extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /** @return BelongsTo<ParentUser, $this> */
    public function parentUser(): BelongsTo
    {
        return $this->belongsTo(ParentUser::class, 'parent_user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
