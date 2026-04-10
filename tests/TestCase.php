<?php

namespace LBHurtado\SettlementEnvelope\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use LBHurtado\SettlementEnvelope\SettlementEnvelopeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\Support\Creation\ValidationStrategy;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Spatie\LaravelData\LaravelDataServiceProvider::class,
            SettlementEnvelopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $driversRoot = __DIR__.'/../resources/stubs/drivers';
        $publicRoot = __DIR__.'/Fixtures/storage/public';

        if (! is_dir($publicRoot)) {
            mkdir($publicRoot, 0777, true);
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('filesystems.default', 'local');

        $app['config']->set('filesystems.disks.settlement-envelope-drivers', [
            'driver' => 'local',
            'root' => $driversRoot,
            'throw' => false,
        ]);

        $app['config']->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => $publicRoot,
            'url' => '/storage',
            'visibility' => 'public',
            'throw' => false,
        ]);

        $app['config']->set('settlement-envelope.driver_disk', 'settlement-envelope-drivers');
        $app['config']->set('settlement-envelope.storage_disk', 'public');
        $app['config']->set('settlement-envelope.audit.enabled', true);
        $app['config']->set('settlement-envelope.manifest.enabled', true);
        $app['config']->set('settlement-envelope.actor_model', TestUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'LBHurtado\\SettlementEnvelope\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->prepareFixtureDirectories();
        $this->bootSpatieLaravelDataConfig();
    }

    protected function tearDown(): void
    {
        $this->cleanFixtureStorage();

        parent::tearDown();
    }

    protected function prepareFixtureDirectories(): void
    {
        foreach ([
                     __DIR__.'/Fixtures',
                     __DIR__.'/Fixtures/storage',
                     __DIR__.'/Fixtures/storage/public',
                 ] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
        }
    }

    protected function cleanFixtureStorage(): void
    {
        $publicRoot = __DIR__.'/Fixtures/storage/public';

        if (is_dir($publicRoot)) {
            File::deleteDirectory($publicRoot);
            mkdir($publicRoot, 0777, true);
        }
    }

    protected function bootSpatieLaravelDataConfig(): void
    {
        $config = $this->app['config']->get('data');

        if (! is_array($config)) {
            $defaultConfigPath = __DIR__.'/../vendor/spatie/laravel-data/config/data.php';

            if (! file_exists($defaultConfigPath)) {
                throw new \RuntimeException('Unable to locate spatie/laravel-data config file.');
            }

            $config = require $defaultConfigPath;
        }

        $config['validation_strategy'] = ValidationStrategy::Disabled->value;

        $this->app['config']->set('data', $config);
    }
}