<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'student_id' => $this->student_id,
            'cashier_id' => $this->cashier_id,
            'receipt_number' => $this->receipt_number,
            'payment_method' => $this->payment_method?->value,
            'payment_method_label' => $this->payment_method?->label(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'discount_reason' => $this->discount_reason,
            'total' => $this->total,
            'amount_tendered' => $this->amount_tendered,
            'change_amount' => $this->change_amount,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'is_credit' => $this->is_credit,
            'credit_amount' => $this->credit_amount,
            'points_earned' => $this->points_earned,
            'status' => $this->status?->value,
            'voided_at' => $this->voided_at?->toDateTimeString(),
            'void_reason' => $this->void_reason,
            'created_at' => $this->created_at?->toDateTimeString(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'full_name' => $this->student->full_name,
                'grade_level' => $this->student->grade_level,
                'section' => $this->student->section,
                'wallet_balance' => $this->student->wallet?->balanceFloat ?? 0,
                'credit_balance' => $this->student->credit_balance,
                'points' => $this->student->points,
            ]),
            'cashier' => $this->whenLoaded('cashier', fn () => [
                'id' => $this->cashier->id,
                'name' => $this->cashier->name,
            ]),
        ];
    }
}
