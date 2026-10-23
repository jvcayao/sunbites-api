<?php

namespace App\Http\Requests\Kitchen;

use App\Models\BranchMonthlyAmount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchMonthlyAmountRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Another branch's record is not found, so 404 wins over a 422 for its body (H4).
     *
     * @throws ModelNotFoundException
     */
    protected function prepareForValidation(): void
    {
        /** @var BranchMonthlyAmount $branchMonthlyAmount */
        $branchMonthlyAmount = $this->route('branchMonthlyAmount');

        if ($branchMonthlyAmount->branch_id !== app('active_branch')->id) {
            throw (new ModelNotFoundException)->setModel(BranchMonthlyAmount::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:0', 'max:31'],
            'amount' => ['nullable', 'numeric', 'min:0', Rule::prohibitedIf($this->integer('days') === 0)],
        ];
    }
}
