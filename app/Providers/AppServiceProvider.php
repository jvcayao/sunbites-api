<?php

namespace App\Providers;

use App\Listeners\AuthEventSubscriber;
use App\Models\PreRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureDefaults();

        Event::subscribe(AuthEventSubscriber::class);

        $this->bindCrossBranchRouteModels();

        $this->registerSearchMacros();

        $this->loadBroadcastChannels();
    }

    /**
     * Search macros on the query builder; Eloquent builders forward to them. `whereContainsLiteral`
     * matches `$term` anywhere in `$column` with `%`, `_` and `!` taken literally (`ESCAPE '!'`
     * behaves the same on MySQL and SQLite). `whereAnyContainsLiteral` groups it over several
     * columns like `whereAny`. Column names are literals from code, never input.
     */
    private function registerSearchMacros(): void
    {
        Builder::macro('whereContainsLiteral', function (string $column, string $term, string $boolean = 'and') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

            return $this->whereRaw("{$column} LIKE ? ESCAPE '!'", ["%{$escaped}%"], $boolean);
        });

        Builder::macro('whereAnyContainsLiteral', function (array $columns, string $term, string $boolean = 'and') {
            return $this->where(function ($query) use ($columns, $term) {
                foreach ($columns as $column) {
                    $query->whereContainsLiteral($column, $term, 'or');
                }
            }, boolean: $boolean);
        });
    }

    /**
     * Loads the channel authorization callbacks without registering Laravel's default
     * top-level `/broadcasting/auth` route (which `channels:` in bootstrap/app.php's
     * withRouting would add): the API already registers its own authenticated auth
     * routes in routes/kitchen-api.php and routes/portal-api.php.
     */
    private function loadBroadcastChannels(): void
    {
        require base_path('routes/channels.php');
    }

    /**
     * Pre-registrations resolve across branches on purpose.
     *
     * `SetActiveBranch` now runs before `SubstituteBindings`, so every bound model is
     * branch-scoped by default. Pre-registration approval is a documented exception: staff
     * working with one active branch may approve a pre-registration belonging to another
     * branch they have access to, and `PreRegistrationController::approve()` already
     * resolves and locks the record with `withoutBranch()`. Branch selection is still gated
     * by `SetActiveBranch`, which rejects any branch the user cannot access.
     */
    private function bindCrossBranchRouteModels(): void
    {
        Route::bind(
            'preRegistration',
            fn (string $value) => PreRegistration::withoutBranch()->findOrFail($value),
        );
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

        Model::preventLazyLoading(! $this->app->isProduction());

        // Throw only in the test suite. `local` (developers running the POS/portal against the
        // local API) and `staging` log instead, so an untested lazy load never breaks a page.
        if (! $this->app->environment('testing')) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('Lazy loading violation', ['model' => $model::class, 'relation' => $relation]);
            });
        }

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
