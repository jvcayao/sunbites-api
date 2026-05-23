<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('active_branch') || ! app('active_branch')) {
            return;
        }

        $builder->where($model->getTable().'.branch_id', app('active_branch')->id);
    }
}
