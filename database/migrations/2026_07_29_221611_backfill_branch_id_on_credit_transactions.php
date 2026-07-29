<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill the branch snapshot on credit ledger entries written before the
     * column existed. Kept separate from the schema change so a partial failure
     * cannot leave the table half-altered.
     */
    public function up(): void
    {
        DB::table('credit_transactions')
            ->whereNull('branch_id')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                $studentIds = collect($rows)->pluck('student_id')->unique()->all();

                $branchByStudent = DB::table('students')
                    ->whereIn('id', $studentIds)
                    ->pluck('branch_id', 'id');

                foreach ($rows as $row) {
                    $branchId = $branchByStudent[$row->student_id] ?? null;

                    if ($branchId === null) {
                        continue;
                    }

                    DB::table('credit_transactions')
                        ->where('id', $row->id)
                        ->update(['branch_id' => $branchId]);
                }
            });
    }

    /**
     * Data-only migration. Rolling back the accompanying schema migration drops
     * the column outright, so there is nothing to reverse here.
     */
    public function down(): void
    {
        //
    }
};
