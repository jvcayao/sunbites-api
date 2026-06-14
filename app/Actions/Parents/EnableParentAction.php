<?php

namespace App\Actions\Parents;

use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class EnableParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->forceFill(['disabled_at' => null, 'email_verified_at' => null])->save();

        $token = Password::broker('parents')->createToken($parent);
        Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));
    }
}
