<?php

namespace App\Providers;

use App\Events\Payment\PaymentWasCreated;
use App\Events\Quote\QuoteWasApproved;
use App\Events\Quote\QuoteWasMarkedApproved;
use App\Events\Quote\QuoteWasRejected;
use App\Listeners\Ops\PaymentReceivedListener;
use App\Listeners\Ops\QuoteDecisionListener;
use App\Services\Ops\OpsAccess;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the PCFix Hub operations module on top of Invoice Ninja without
 * modifying Invoice Ninja's own providers.
 */
class PCFixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('pcfix.enabled')) {
            return;
        }

        // One Gate per ops ability: "ops.repair_jobs.manage", ...
        foreach (array_keys(config('pcfix.abilities')) as $ability) {
            Gate::define('ops.' . $ability, fn (User $user) => OpsAccess::userCan($user, $ability));
        }

        // React to the existing quote approval flow (client portal + admin "mark approved").
        Event::listen(QuoteWasApproved::class, [QuoteDecisionListener::class, 'approved']);
        Event::listen(QuoteWasMarkedApproved::class, [QuoteDecisionListener::class, 'approved']);
        Event::listen(QuoteWasRejected::class, [QuoteDecisionListener::class, 'rejected']);
        Event::listen(PaymentWasCreated::class, [PaymentReceivedListener::class, 'handle']);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('pcfix:daily')->dailyAt('07:00')->name('pcfix-daily')->withoutOverlapping();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/ops.php'));
            Route::middleware('client')->group(base_path('routes/ops_portal.php'));
            Route::prefix('')->middleware('api')->group(base_path('routes/ops_api.php'));
        }

        $this->loadViewsFrom(resource_path('views/ops'), 'ops');

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Console\Commands\PCFixDaily::class]);
        }
    }
}
