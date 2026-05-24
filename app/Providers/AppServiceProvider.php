<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use App\Policies\OrderPolicy;
use App\Policies\ParentStudentPolicy;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureDefaults();

        Event::subscribe(AuthEventSubscriber::class);

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Student::class, ParentStudentPolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(8)->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(8)->mixedCase()->numbers(),
        );

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(10)->by('login:'.$request->input('email', '')),
        ]);

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perMinute(5)->by('pwd-reset:'.$request->input('email', $request->input('token', ''))),
        ]);

        RateLimiter::for('portal-login', fn (Request $request) => Limit::perMinutes(5, 5)->by($request->ip()));

        RateLimiter::for('portal-forgot-password', fn (Request $request) => Limit::perMinutes(5, 5)->by($request->ip()));
    }
}
