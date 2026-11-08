<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditLedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => "credit-{$this->id}",
            'date' => $this->created_at?->toIso8601String(),
            'type' => $this->type?->value,
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method?->value,
            'reference_number' => $this->reference_number,
            'note' => $this->notes,
        ];
    }
}
