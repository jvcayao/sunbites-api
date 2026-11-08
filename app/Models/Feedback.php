<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('feedbacks')]
#[Fillable([
    'parent_id',
    'student_id',
    'branch_id',
    'rating',
    'category',
    'message',
    'is_read',
    'admin_reply',
    'replied_at',
])]
class Feedback extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'is_read' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ParentUser, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentUser::class, 'parent_id');
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
