<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasBranch;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasBranch, HasFactory, LogsActivity;

    protected $fillable = [
        'branch_id',
        'student_id',
        'cashier_id',
        'receipt_number',
        'payment_method',
        'subtotal',
        'discount_amount',
        'discount_reason',
        'total',
        'amount_tendered',
        'change_amount',
        'reference_number',
        'notes',
        'is_credit',
        'credit_amount',
        'points_earned',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $recordEvents = ['created', 'updated'];

    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'is_credit' => 'boolean',
            'points_earned' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'void_reason', 'voided_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $eventName) => "orders.{$eventName}");
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public static function generateReceiptNumber(int $branchId): string
    {
        $branch = Branch::findOrFail($branchId);
        $year = now()->year;
        $prefix = strtoupper($branch->slug).'-'.$year.'-';

        $lastOrder = static::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('receipt_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $sequence = $lastOrder
            ? (int) substr($lastOrder->receipt_number, strlen($prefix)) + 1
            : 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
