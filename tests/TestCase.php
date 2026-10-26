<?php

namespace Laraditz\Courier\SpxExpress\Tests;

use Laraditz\Courier\CourierServiceProvider;
use Laraditz\Courier\SpxExpress\SpxExpressServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CourierServiceProvider::class,
            SpxExpressServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The app id/secret pair is SPX's published check-sign test vector, so the
        // signer is exercised against values with a known answer.
        $app['config']->set('courier.drivers.spxexpress', [
            'app_id'      => '100000',
            'app_secret'  => 'H25HY53GO4BA2GQ',
            'user_id'     => '239404781503925',
            'user_secret' => '85f0d570-a265-4d9d-857e-b30aa57c4fbe',
            'service_type' => 1,
            'default_fulfillment' => 'dropoff',
            'webhook_timestamp_tolerance' => 900,
            'sandbox'     => true,
            'sandbox_url' => 'https://test-stable.spx.com.my',
            'base_url'    => 'https://spx.com.my',
            'timeout'     => 30,
        ]);
    }

    // courier_api_logs and courier_webhook_logs live in the core package. Without
    // them, ApiLogWriter's catch (Throwable) turns every failed write into a silent
    // Log::error and the suite goes green while logging nothing at all.
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laraditz/courier/database/migrations');
    }

    protected function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(__DIR__."/fixtures/{$name}.json"),
            true
        );
    }
}
