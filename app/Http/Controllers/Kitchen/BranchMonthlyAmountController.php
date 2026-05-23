<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\BranchMonthlyAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchMonthlyAmountController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'school_month' => ['required', Rule::enum(SchoolMonth::class)],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        BranchMonthlyAmount::updateOrCreate(
            ['branch_id' => $validated['branch_id'], 'school_month' => $validated['school_month']],
            ['amount' => $validated['amount']],
        );

        return back()->with('success', 'Monthly amount updated.');
    }
}
