<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'student_number' => $this->student_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'grade_level' => $this->grade_level,
            'section' => $this->section,
            'birthday' => $this->birthday?->toDateString(),
            'has_photo' => (bool) $this->photo_path,
            'photo_url' => $this->photo_path ? url("/api/v1/students/{$this->id}/photo") : null,
            'allergies' => $this->allergies,
            'notes' => $this->notes,
            'qr_code' => $this->qr_code,
            'student_type' => $this->student_type?->value,
            'student_type_label' => $this->student_type?->label(),
            'enrollment_status' => $this->enrollment_status?->value,
            'enrollment_status_label' => $this->enrollment_status?->label(),
            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'points' => $this->points,
            'total_spent' => $this->total_spent,
            'credit_balance' => $this->credit_balance,
            'wallet_balance' => $this->whenLoaded('wallet', fn () => $this->wallet?->balanceFloat ?? 0),
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'id' => $c->id,
                'full_name' => $c->full_name,
                'relationship' => $c->relationship,
                'phone' => $c->phone,
                'address' => $c->address,
                'email' => $c->email,
                'is_primary' => $c->is_primary,
            ])),
            'monthly_payments' => $this->whenLoaded('monthlyPayments', fn () => $this->monthlyPayments->map(fn ($p) => [
                'id' => $p->id,
                'school_month' => $p->school_month?->value,
                'school_month_label' => $p->school_month?->label(),
                'year' => $p->year,
                'status' => $p->status,
                'amount' => $p->amount,
                'recorded_at' => $p->recorded_at?->toDateTimeString(),
            ])),
        ];
    }
}
