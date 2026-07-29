<?php

namespace App\Models;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Services\CreditLedgerService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry in the student credit ledger.
 *
 * Written exclusively by {@see CreditLedgerService}. The service is the
 * only place allowed to touch this table or `students.credit_balance`, which keeps the
 * invariant `credit_balance = Σcharged − Σsettled − Σwaived − Σvoided` intact.
 *
 * Deliberately does NOT use the HasBranch trait: the global BranchScope would filter the
 * parent portal ledger query, where no active branch is bound. Branch filtering stays
 * explicit in the report controllers, using the `branch_id` snapshot written here.
 */
class CreditTransaction extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'student_id',
        'branch_id',
        'order_id',
        'type',
        'amount',
        'payment_method',
        'reference_number',
        'wallet_transaction_id',
        'notes',
        'performed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'payment_method' => CreditSettlementMethod::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
