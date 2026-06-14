<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class RestoreParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->restore();
        $parent->forceFill(['disabled_at' => null])->save();

        (new EnableParentAction)->execute($parent);
    }
}
