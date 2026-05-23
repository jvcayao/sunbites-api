<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'order_id',
        'type',
        'amount',
        'notes',
        'performed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
