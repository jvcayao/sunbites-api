<?php

namespace App\Models;

use Database\Factories\WalletTopupVoidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of a voided wallet top-up.
 *
 * Written exclusively by WalletController::voidTopUp(). Never updated after creation —
 * mirrors CreditTransaction's append-only convention. Deliberately does NOT use HasBranch:
 * the global BranchScope would break this table's use from contexts with no active branch
 * bound (e.g. a future cross-branch report), so branch_id stays an explicit snapshot column,
 * matching CreditTransaction's own documented rationale.
 */
#[WithoutTimestamps]
#[Fillable([
    'student_id',
    'branch_id',
    'wallet_transaction_id',
    'refund_wallet_transaction_id',
    'credit_transaction_id',
    'original_amount',
    'voided_amount',
    'shortfall_amount',
    'void_reason',
    'voided_by',
    'created_at',
])]
class WalletTopupVoid extends Model
{
    /** @use HasFactory<WalletTopupVoidFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'voided_amount' => 'decimal:2',
            'shortfall_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
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

    /** @return BelongsTo<CreditTransaction, $this> */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(CreditTransaction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
