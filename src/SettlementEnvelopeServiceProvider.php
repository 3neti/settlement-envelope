<?php

namespace LBHurtado\SettlementEnvelope;

use Illuminate\Support\ServiceProvider;
use LBHurtado\SettlementEnvelope\Console\InstallDriversCommand;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowAccessPolicy;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowCatalog;
use LBHurtado\SettlementEnvelope\Services\DenyWorkflowAccess;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\EnvelopeService;
use LBHurtado\SettlementEnvelope\Services\GateEvaluator;
use LBHurtado\SettlementEnvelope\Services\PayloadValidator;
use LBHurtado\SettlementEnvelope\Services\YamlWorkflowCatalog;

class SettlementEnvelopeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(WorkflowAccessPolicy::class, DenyWorkflowAccess::class);
        $this->app->bindIf(WorkflowCatalog::class, YamlWorkflowCatalog::class);

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
