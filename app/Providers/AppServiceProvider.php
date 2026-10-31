<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('helpers.php');

        // The one place a payment provider is chosen for installments (tests bind a fake over it).
        // config('installments.gateway') = paymongo | sandbox. The sandbox is never bound unless the key is sk_test_.
        $this->app->bind(\App\Support\Installments\InstallmentGateway::class, function () {
            if (config('installments.gateway') === 'sandbox') {
                if (\App\Support\Installments\SandboxInstallmentGateway::allowed()) {
                    return new \App\Support\Installments\SandboxInstallmentGateway();
                }
                \Illuminate\Support\Facades\Log::critical('installments.gateway is "sandbox" but the PayMongo key is not a test key: using the real PayMongo driver.');
            }

            return new \App\Support\Installments\PayMongoInstallmentGateway();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination::lp');
        Paginator::defaultSimpleView('pagination::lp-simple');

        // @titlecase($text): escaped Title Case display text (App\Support\TitleCase)
        Blade::directive('titlecase', fn ($expr) => "<?php echo e(\App\Support\TitleCase::apply({$expr})); ?>");
    }
}
