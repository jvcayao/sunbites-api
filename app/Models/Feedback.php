<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    protected $table = 'feedbacks';

    public const UPDATED_AT = null;

    protected $fillable = [
        'parent_id',
        'student_id',
        'branch_id',
        'rating',
        'category',
        'message',
        'is_read',
        'admin_reply',
        'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'is_read' => 'boolean',
            'replied_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentUser::class, 'parent_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
