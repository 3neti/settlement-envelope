<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;
use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\DriverSourceRegistry;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Storage::fake('settlement-envelope-drivers');
    Cache::flush();
    $this->root = sys_get_temp_dir().'/driver-source-'.bin2hex(random_bytes(8));
    File::makeDirectory($this->root.'/package.test', 0755, true);
    $this->data = ['driver' => ['id' => 'package.test', 'version' => '1.0.0', 'title' => 'Bundled']];
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    $this->registry = app(DriverSourceRegistry::class);
    $this->registry->register('vendor/test', $this->root);
    $this->service = app(DriverService::class);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('discovers and loads packages without copying files or executing adapters', function () {
    expect($this->service->list())->toHaveCount(1)
        ->and($this->service->load('package.test')->title)->toBe('Bundled')
        ->and($this->service->loadExact('package.test', '1.0.0')->title)->toBe('Bundled')
        ->and($this->service->exists('package.test', '1.0.0'))->toBeTrue()
        ->and(Storage::disk('settlement-envelope-drivers')->allFiles())->toBeEmpty();
    expect(fn () => $this->service->load('package.test', '9.0.0'))->toThrow(DriverNotFoundException::class);
});

test('deduplicates identical definitions and rejects differing definitions unless host explicitly overrides', function () {
    $disk = Storage::disk('settlement-envelope-drivers');
    $disk->put('package.test/v1.0.0.yaml', Yaml::dump($this->data));
    expect($this->service->list())->toHaveCount(1);
    $this->data['driver']['title'] = 'Host';
    $disk->put('package.test/v1.0.0.yaml', Yaml::dump($this->data));
    expect(fn () => $this->service->list())->toThrow(InvalidDriverException::class);
    config(['settlement-envelope.driver_host_overrides' => ['package.test@1.0.0']]);
    expect($this->service->loadExact('package.test', '1.0.0')->title)->toBe('Host');
});

test('registries are isolated and repeated registrations are idempotent', function () {
    $this->registry->register('vendor/test', $this->root);
    expect($this->registry->roots())->toHaveCount(1)
        ->and((new DriverSourceRegistry)->roots())->toBeEmpty();
    expect(fn () => $this->registry->register('vendor/test', sys_get_temp_dir()))->toThrow(InvalidDriverException::class);
    expect(fn () => $this->registry->register('missing', $this->root.'/missing'))->toThrow(InvalidDriverException::class);
});

test('loads external package schemas from their source and invalidates source-aware cache', function () {
    $this->data['payload']['schema']['uri'] = 'schema.json';
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    File::put($this->root.'/package.test/schema.json', '{"type":"object"}');
    Cache::put('envelope_driver:package.test:1.0.0', ['id' => 'stale']);
    expect($this->service->getSchema($this->service->load('package.test', '1.0.0')))->toBe(['type' => 'object']);
    File::put($this->root.'/package.test/schema.json', '{"type":"string"}');
    expect($this->service->getSchema($this->service->load('package.test', '1.0.0')))->toBe(['type' => 'string']);
});

test('rejects unsafe external schema paths', function (string $uri) {
    $this->data['payload']['schema']['uri'] = $uri;
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    expect(fn () => $this->service->loadExact('package.test', '1.0.0'))->toThrow(InvalidDriverException::class);
})->with(['../../secret.json', 'https://example.com/schema.json', '/tmp/schema.json']);

test('rejects package symlink escapes', function () {
    File::delete($this->root.'/package.test/v1.0.0.yaml');
    symlink(__FILE__, $this->root.'/package.test/v1.0.0.yaml');
    expect(fn () => $this->service->list())->toThrow(InvalidDriverException::class);
});

test('validates package identity and version instead of falling back', function () {
    $this->data['driver']['version'] = '2.0.0';
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    expect(fn () => $this->service->list())->toThrow(InvalidDriverException::class);
});

test('retains legacy flat host drivers with their declared version', function () {
    Storage::disk('settlement-envelope-drivers')->put('legacy.yaml', Yaml::dump(['driver' => ['id' => 'legacy', 'version' => '2.0.0']]));
    expect($this->service->loadExact('legacy', '2.0.0')->version)->toBe('2.0.0');
});

test('retains legacy host metadata defaults without blocking package drivers', function (array $metadata) {
    Storage::disk('settlement-envelope-drivers')->put('legacy.yaml', Yaml::dump(['driver' => $metadata]));
    $legacy = $this->service->load('legacy');
    expect($legacy->id)->toBe('legacy')
        ->and($legacy->version)->toBe('1.0.0')
        ->and($legacy->title)->toBe('Legacy')
        ->and($this->service->list())->toHaveCount(2)
        ->and($this->service->workflowReferences())->toBeEmpty()
        ->and($this->service->loadExact('package.test', '1.0.0')->title)->toBe('Bundled');
})->with([
    'missing both' => [['title' => 'Legacy']],
    'missing id' => [['title' => 'Legacy', 'version' => '1.0.0']],
    'missing version' => [['title' => 'Legacy', 'id' => 'legacy']],
]);

test('does not apply legacy metadata defaults to package or workflow definitions', function (string $source) {
    $data = ['driver' => ['title' => 'Legacy']];
    if ($source === 'host') {
        $data['workflow'] = [];
        Storage::disk('settlement-envelope-drivers')->put('legacy.yaml', Yaml::dump($data));
    } else {
        File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($data));
    }
    expect(fn () => $this->service->list())->toThrow(InvalidDriverException::class);
})->with(['host', 'package']);

test('discovers package workflows and resolves exact cross-source inheritance', function () {
    $this->data['payload']['schema']['inline'] = ['type' => 'object'];
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    $child = [
        'driver' => ['id' => 'host.child', 'version' => '1.0.0'],
        'extends' => ['package.test@1.0.0'],
        'workflow' => ['service' => 'test', 'title' => 'Test', 'entry_methods' => ['public_endpoint'], 'requires_review' => false],
    ];
    Storage::disk('settlement-envelope-drivers')->put('host.child/v1.0.0.yaml', Yaml::dump($child));
    expect($this->service->workflowReferences())->toBe([['id' => 'host.child', 'version' => '1.0.0']])
        ->and($this->service->getSchema($this->service->loadExact('host.child', '1.0.0', true)))->toBe(['type' => 'object']);
    $this->data['workflow'] = $child['workflow'];
    File::put($this->root.'/package.test/v1.0.0.yaml', Yaml::dump($this->data));
    expect($this->service->workflowReferences())->toHaveCount(2);
});
