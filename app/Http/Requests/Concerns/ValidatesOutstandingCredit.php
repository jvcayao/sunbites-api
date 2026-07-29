<?php

namespace App\Http\Requests\Concerns;

use App\Models\Student;
use Closure;

/**
 * Shared amount rule for the credit settle and waive requests.
 *
 * A closure rule rather than a `Rule` class because `app/Rules/` does not exist in this
 * project and CLAUDE.md forbids creating new base directories without approval.
 */
trait ValidatesOutstandingCredit
{
    /**
     * Reject an amount larger than what the student currently owes, naming the figure.
     *
     * Validation is not a transaction boundary, so CreditLedgerService re-checks this
     * under `lockForUpdate` before writing.
     */
    protected function notExceedingOutstandingCredit(Student $student): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($student): void {
            $outstanding = round((float) $student->credit_balance, 2);

            if (round((float) $value, 2) > $outstanding) {
                $fail('Amount exceeds outstanding credit of '.number_format($outstanding, 2).'.');
            }
        };
    }
}
