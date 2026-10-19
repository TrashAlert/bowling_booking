<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ActivityLog;
use App\Services\Branding;
use App\Services\Payments\DepositPaymentProvider;
use App\Services\VenueRules;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use InvalidArgumentException;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The errors shown as a page of the app: not allowed, not found, timed
     * out, too many tries, a fault, and down for maintenance.
     *
     * @var list<int>
     */
    private const ERROR_PAGES = [403, 404, 419, 429, 500, 503];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One for the whole request or command, so a scheduled job can put
        // every line it writes down to the scheduler.
        $this->app->singleton(ActivityLog::class);

        $this->app->bind(WebPush::class, fn () => new WebPush(['VAPID' => [
            'subject' => config('services.web_push.subject'),
            'publicKey' => config('services.web_push.public_key'),
            'privateKey' => config('services.web_push.private_key'),
        ]]));

        // Whoever takes deposits: the provider named in config/bowling.php.
        $this->app->bind(DepositPaymentProvider::class, function () {
            $name = config('bowling.deposit_payments.provider');
            $class = config("bowling.deposit_payments.providers.{$name}");

            if ($class === null) {
                throw new InvalidArgumentException("No deposit payment provider is set up under the name [{$name}]. See config/bowling.php.");
            }

            return $this->app->make($class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
        $this->applySavedRules();
        $this->configureErrorPages();
        $this->logSignIns();

        // The page every screen starts from carries the business name, for
        // the browser tab's title, and its logo, for the tab's icon.
        View::composer('app', function ($view) {
            $branding = app(Branding::class)->forPages();

            $view->with(['businessName' => $branding['name'], 'businessLogoUrl' => $branding['logoUrl']]);
        });
    }

    /**
     * Use the booking rules an admin saved under Settings in place of their
     * .env values.
     *
     * The app also starts where there is no database to read them from yet:
     * during installation, and before the first migration. The .env values
     * simply stay in force then.
     */
    protected function applySavedRules(): void
    {
        try {
            app(VenueRules::class)->applySaved();
        } catch (QueryException) {
            //
        }
    }

    /**
     * Show errors as a page of the app, with the business's name and colours,
     * in place of Laravel's bare error pages: a mistyped link, a page that
     * timed out, too many tries in a row, maintenance, or a fault.
     *
     * A fault is left to Laravel's own page while debugging is on, so the
     * details stay visible during development. Requests that want JSON, such
     * as a payment provider's notice, are left alone too.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            $status = $response->statusCode();
            $shown = in_array($status, self::ERROR_PAGES, true) && ! ($status === 500 && config('app.debug'));

            if (! $shown || $response->request->expectsJson()) {
                return null;
            }

            return $response->render('error', ['status' => $status])->withSharedData();
        });
    }

    /**
     * Write logging in, failed attempts and logging out to the activity log.
     * A failed attempt is put down to whoever typed the email, as typed.
     */
    protected function logSignIns(): void
    {
        Event::listen(function (Login $event) {
            $user = $event->user;
            $log = app(ActivityLog::class);

            if ($user instanceof User) {
                $log->as(ActivityLog::ACTOR_USER, $user->id, $user->name, fn () => $log->record('auth.logged_in', "{$user->name} logged in.", $user));
            }
        });

        Event::listen(function (Logout $event) {
            $user = $event->user;
            $log = app(ActivityLog::class);

            if ($user instanceof User) {
                $log->as(ActivityLog::ACTOR_USER, $user->id, $user->name, fn () => $log->record('auth.logged_out', "{$user->name} logged out.", $user));
            }
        });

        Event::listen(function (Failed $event) {
            $email = Str::limit((string) ($event->credentials['email'] ?? ''), 100);
            $log = app(ActivityLog::class);

            $log->as(ActivityLog::ACTOR_UNKNOWN, null, $email === '' ? 'Unknown' : $email, fn () => $log->record(
                'auth.login_failed',
                $event->user === null
                    ? "Failed login as {$email}: no account has that email."
                    : "Failed login as {$email}: wrong password.",
                $event->user instanceof User ? $event->user : null,
            ));
        });
    }

    /**
     * Believe the proxy the public pages come through about who it is passing
     * on. Without this every customer looks like the proxy itself, so they
     * would share one limit on how often the waitlist can be joined, and
     * links would be made for HTTP while the customer is on HTTPS.
     *
     * Nothing is trusted unless TRUSTED_PROXIES is set, so a visitor who
     * reaches the app directly can't pass itself off as someone else.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (blank($proxies)) {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_map(trim(...), explode(',', $proxies)));
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

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
