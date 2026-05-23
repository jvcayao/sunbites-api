<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

class AuthEventSubscriber
{
    public function handleLogin(Login $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties([
                'ip' => request()->ip(),
                'guard' => $event->guard,
                'branch_id' => request()->header('X-Branch-Id'),
            ])
            ->log('auth.login');
    }

    public function handleLogout(Logout $event): void
    {
        activity('auth')
            ->causedBy($event->user)
            ->withProperties(['ip' => request()->ip(), 'guard' => $event->guard])
            ->log('auth.logout');
    }

    public function handleFailed(Failed $event): void
    {
        $email = isset($event->credentials['email'])
            ? hash('sha256', strtolower($event->credentials['email']))
            : null;

        activity('auth')
            ->withProperties([
                'ip' => request()->ip(),
                'guard' => $event->guard,
                'email_hash' => $email,
            ])
            ->log('auth.failed');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
        ];
    }
}
