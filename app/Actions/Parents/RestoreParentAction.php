<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class RestoreParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->restore();

        (new EnableParentAction)->execute($parent);
    }
}
