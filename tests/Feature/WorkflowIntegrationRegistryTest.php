<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowAccessPolicy;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowCatalog;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowIntegrationAdapter;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowIntegrationResult;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowSubmission;
use LBHurtado\SettlementEnvelope\Data\WorkflowContext;
use LBHurtado\SettlementEnvelope\Enums\WorkflowIntegrationStatus;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use LBHurtado\SettlementEnvelope\Services\WorkflowIntegrationRegistry;
use Symfony\Component\Yaml\Yaml;

class RegistryTestAdapter implements WorkflowIntegrationAdapter
{
    public int $submissions = 0;

    public function __construct(public string $id = 'integration.demo', public string $version = '1.0.0') {}

    public function workflowId(): string
    {
        return $this->id;
    }

    public function workflowVersion(): string
    {
        return $this->version;
    }

    public function submit(WorkflowSubmission $submission): WorkflowIntegrationResult
    {
        $this->submissions++;

        return new class implements WorkflowIntegrationResult
        {
            public function reference(): string
            {
                return 'demo-reference';
            }

            public function status(): WorkflowIntegrationStatus
            {
                return WorkflowIntegrationStatus::AwaitingReview;
            }

            public function demonstrationOnly(): bool
            {
                return true;
            }
        };
    }
}

beforeEach(function () {
    Http::fake();
    Http::preventStrayRequests();
    Storage::fake('integration-drivers');
    config()->set('settlement-envelope.driver_disk', 'integration-drivers');
    app()->forgetInstance(DriverService::class);
    app()->bind(WorkflowAccessPolicy::class, fn () => new class implements WorkflowAccessPolicy
    {
        public function allows(WorkflowContext $context, string $id, string $version): bool
        {
            return $context->accountId === 'account-1';
        }
    });
    $this->context = new WorkflowContext('actor-1', 'account-1');
    $this->definition = Yaml::parseFile(__DIR__.'/../Fixtures/drivers/philhealth.bst.demo/v1.0.0.yaml');
    $this->definition['driver']['id'] = 'integration.demo';
    $this->definition['workflow'] = [
        'service' => 'demo', 'title' => 'Integration demonstration',
        'entry_methods' => ['public_endpoint'], 'requires_review' => true,
    ];
    Storage::disk('integration-drivers')->put('integration.demo/v1.0.0.yaml', Yaml::dump($this->definition, 10));
    $this->adapter = new RegistryTestAdapter;
});

afterEach(function () {
    Http::assertNothingSent();
});

test('integration resolution is exact and never submits or evaluates evidence gates', function () {
    $otherVersion = new RegistryTestAdapter(version: '2.0.0');
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$otherVersion, $this->adapter]);

    expect($registry->resolve('integration.demo', '1.0.0', $this->context))->toBe($this->adapter)
        ->and($this->adapter->submissions)->toBe(0)
        ->and($otherVersion->submissions)->toBe(0);
    expect(fn () => $registry->resolve('integration.demo', '2.0.0', $this->context))->toThrow(DriverNotFoundException::class);
});

test('integration resolution respects catalog authorization', function () {
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$this->adapter]);

    expect(fn () => $registry->resolve('integration.demo', '1.0.0', new WorkflowContext('actor-2', 'account-2')))
        ->toThrow(DriverNotFoundException::class);
    expect($this->adapter->submissions)->toBe(0);
});

test('integration resolution rejects missing or invalid connection readiness', function (mixed $connection) {
    $this->definition['workflow']['connection'] = 'demo-connection';
    Storage::disk('integration-drivers')->put('integration.demo/v1.0.0.yaml', Yaml::dump($this->definition, 10));
    config()->set('settlement-envelope.connections.demo-connection', $connection);
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$this->adapter]);

    expect(fn () => $registry->resolve('integration.demo', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
    expect($this->adapter->submissions)->toBe(0);
})->with([null, [[]], [['driver' => 'http', 'base_url' => 'http://invalid.example']]]);

test('integration resolution never falls back to another adapter version', function () {
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [new RegistryTestAdapter(version: '2.0.0')]);
    expect(fn () => $registry->resolve('integration.demo', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
});

test('integration resolution requires explicitly supplied adapters', function () {
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), []);
    expect(fn () => $registry->resolve('integration.demo', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
    expect(app()->bound(WorkflowIntegrationRegistry::class))->toBeFalse();
});

test('integration registry rejects duplicate identities', function () {
    expect(fn () => new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$this->adapter, new RegistryTestAdapter]))
        ->toThrow(InvalidArgumentException::class);
    expect($this->adapter->submissions)->toBe(0);
});

test('integration registry rejects invalid adapters', function (mixed $adapter) {
    expect(fn () => new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$adapter]))->toThrow(InvalidArgumentException::class);
})->with([
    'not an adapter' => fn () => new stdClass,
    'missing id' => fn () => new RegistryTestAdapter(id: ''),
    'missing version' => fn () => new RegistryTestAdapter(version: ' '),
]);

test('integration registry rejects changed adapter identities', function () {
    $registry = new WorkflowIntegrationRegistry(app(WorkflowCatalog::class), [$this->adapter]);
    $this->adapter->version = '2.0.0';
    expect(fn () => $registry->resolve('integration.demo', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
});

test('integration registry fails closed for inconsistent catalog descriptors', function (string $field) {
    $descriptor = app(WorkflowCatalog::class)->resolve('integration.demo', '1.0.0', $this->context);
    if ($field === 'readiness') {
        $descriptor->readiness->reason = 'connection_invalid';
    } else {
        $descriptor->{$field} = 'unexpected';
    }
    $catalog = Mockery::mock(WorkflowCatalog::class);
    $catalog->shouldReceive('resolve')->once()->with('integration.demo', '1.0.0', $this->context)->andReturn($descriptor);
    $registry = new WorkflowIntegrationRegistry($catalog, [$this->adapter]);

    expect(fn () => $registry->resolve('integration.demo', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
    expect($this->adapter->submissions)->toBe(0);
})->with(['id', 'version', 'readiness']);

test('integration contracts support typed demonstration submissions and results', function () {
    $submission = new class implements WorkflowSubmission
    {
        public function workflowId(): string
        {
            return 'integration.demo';
        }

        public function workflowVersion(): string
        {
            return '1.0.0';
        }

        public function idempotencyKey(): string
        {
            return 'demo-idempotency-key';
        }

        public function fingerprint(): string
        {
            return hash('sha256', 'synthetic-demo-submission');
        }
    };
    $result = $this->adapter->submit($submission);

    expect($submission->workflowId())->toBe('integration.demo')
        ->and($submission->workflowVersion())->toBe('1.0.0')
        ->and($submission->idempotencyKey())->toBe('demo-idempotency-key')
        ->and($submission->fingerprint())->toBe(hash('sha256', 'synthetic-demo-submission'))
        ->and($result->reference())->toBe('demo-reference')
        ->and($result->status())->toBe(WorkflowIntegrationStatus::AwaitingReview)
        ->and($result->demonstrationOnly())->toBeTrue()
        ->and(array_column(WorkflowIntegrationStatus::cases(), 'value'))->toBe(['submitted', 'awaiting_review', 'completed', 'rejected']);
});
