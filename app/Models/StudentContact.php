<?php

namespace App\Models;

use Database\Factories\StudentContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentContact extends Model
{
    /** @use HasFactory<StudentContactFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'full_name',
        'relationship',
        'phone',
        'address',
        'email',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
