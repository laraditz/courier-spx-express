<?php

namespace Laraditz\Courier\SpxExpress;

use Illuminate\Support\ServiceProvider;

class SpxExpressServiceProvider extends ServiceProvider
{
    /**
     * Keys SPX sends that the core redact list does not cover: the sender account's
     * secret travels in every request body, and check-sign in every header set.
     * The Redactor matches whole keys, so 'secret' does not catch 'user_secret'.
     */
    private const REDACT_KEYS = ['user_secret', 'check-sign'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spxexpress.php', 'courier.drivers.spxexpress');
    }

    public function boot(): void
    {
        $this->app->make('courier')->extend(
            'spxexpress',
            fn ($app, $config) => new SpxExpressDriver($config)
        );

        $redact = (array) config('courier.logging.redact', []);

        config(['courier.logging.redact' => array_values(array_unique([...$redact, ...self::REDACT_KEYS]))]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spxexpress.php' => config_path('spxexpress.php'),
            ], 'courier-spx-express-config');
        }
    }
}
