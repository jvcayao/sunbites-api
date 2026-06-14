<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class DisableParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->update(['disabled_at' => now()]);
    }
}
