<?php

use App\Models\ParentUser;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('parents.{parentId}', function (ParentUser $user, int $parentId) {
    return $user->id === $parentId;
});

Broadcast::channel('staff.{userId}', function (User $user, int $userId) {
    return $user->id === $userId;
});
