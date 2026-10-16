<?php

namespace App\Providers;

use App\Services\Branding;
use App\Services\Payments\DepositPaymentProvider;
use App\Services\VenueRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
