<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.manage')
            && $user->id !== $model->id
            && ! ($model->hasRole('admin') && User::role('admin')->count() === 1);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $this->delete($user, $model);
    }

    public function reactivate(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }
}
