<?php

namespace App\Console\Commands;

use App\Enums\PreRegistrationStatus;
use App\Models\PreRegistration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pre-registrations:expire')]
#[Description('Expire pending pre-registrations whose expires_at has passed.')]
class ExpirePreRegistrations extends Command
{
    public function handle(): int
    {
        $count = PreRegistration::withoutBranch()
            ->where('status', PreRegistrationStatus::Pending)
            ->where('expires_at', '<', now())
            ->update(['status' => PreRegistrationStatus::Expired]);

        $this->info("Expired {$count} pre-registration(s).");

        return self::SUCCESS;
    }
}
