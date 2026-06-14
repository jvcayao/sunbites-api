<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class SoftDeleteParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->delete();
    }
}
