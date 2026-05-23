<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Builder;

trait HasBranch
{
    public static function bootHasBranch(): void
    {
        static::addGlobalScope(new BranchScope);

        static::creating(function (self $model): void {
            if (isset($model->branch_id) || ! app()->bound('active_branch') || ! app('active_branch')) {
                return;
            }

            $model->branch_id = app('active_branch')->id;
        });
    }

    public static function withoutBranch(): Builder
    {
        return static::withoutGlobalScope(BranchScope::class);
    }
}
