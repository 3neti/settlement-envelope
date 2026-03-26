<?php

namespace LBHurtado\SettlementEnvelope;

use Illuminate\Support\ServiceProvider;
use LBHurtado\SettlementEnvelope\Console\InstallDriversCommand;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;
use LBHurtado\SettlementEnvelope\Services\GateEvaluator;
use LBHurtado\SettlementEnvelope\Services\PayloadValidator;

class SettlementEnvelopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/settlement-envelope.php',
            'settlement-envelope'
        );

        $this->app->singleton(DriverService::class, function ($app) {
            return new DriverService(
                config('settlement-envelope.driver_disk')
            );
        });

        $this->app->singleton(PayloadValidator::class, function ($app) {
            return new PayloadValidator;
        });

        $this->app->singleton(GateEvaluator::class, function ($app) {
            return new GateEvaluator;
        });

        $this->app->singleton(EnvelopeService::class, function ($app) {
            return new EnvelopeService(
                $app->make(DriverService::class),
                $app->make(PayloadValidator::class),
                $app->make(GateEvaluator::class)
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/settlement-envelope.php' => config_path('settlement-envelope.php'),
        ], 'settlement-envelope-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallDriversCommand::class,
            ]);
        }
    }
}
