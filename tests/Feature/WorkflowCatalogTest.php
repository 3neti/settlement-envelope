<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowAccessPolicy;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowCatalog;
use LBHurtado\SettlementEnvelope\Data\WorkflowContext;
use LBHurtado\SettlementEnvelope\Enums\WorkflowEntryMethod;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;
use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use Spatie\LaravelData\Support\Creation\ValidationStrategy;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Storage::fake('workflow-drivers');
    config()->set('settlement-envelope.driver_disk', 'workflow-drivers');
    app()->forgetInstance(DriverService::class);
    Http::preventStrayRequests();
    $this->context = new WorkflowContext('actor-1', 'account-1');
});

test('strict DTO validation permits messages without placeholders but rejects a missing list', function () {
    config()->set('data.validation_strategy', 'always');
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    unset($definition['workflow']['connection']);
    $definition['workflow']['notifications'] = [[
        'event' => 'submitted', 'sms' => 'Application received.', 'placeholders' => [],
    ]];
    writeWorkflowCatalogFixture($definition);
    $workflow = app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context);
    expect($workflow->workflow->notifications[0]->placeholders)->toBe([]);
    unset($definition['workflow']['notifications'][0]['placeholders']);
    writeWorkflowCatalogFixture($definition);
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))
        ->toThrow(InvalidDriverException::class);
});

function workflowCatalogFixture(string $id = 'aui.purchase', string $version = '1.0.0'): array
{
    return [
        'driver' => ['id' => $id, 'version' => $version, 'title' => 'Demo workflow'],
        'payload' => ['schema' => ['id' => 'demo', 'format' => 'json_schema', 'inline' => ['type' => 'object']]],
        'documents' => ['registry' => []],
        'checklist' => ['template' => []],
        'signals' => ['definitions' => []],
        'gates' => ['definitions' => []],
        'workflow' => [
            'service' => 'aui',
            'title' => 'Purchase demonstration cover',
            'entry_methods' => ['payment_qr', 'public_endpoint'],
            'connection' => 'aui-demo',
            'requires_review' => false,
            'plans' => [[
                'code' => 'PA5000_DAY', 'version' => '1', 'title' => 'One day demo',
                'currency' => 'PHP', 'premium_minor' => 50000, 'benefit_minor' => 500000,
            ]],
        ],
    ];
}

function writeWorkflowCatalogFixture(array $definition, ?string $path = null): void
{
    $path ??= $definition['driver']['id'].'/v'.$definition['driver']['version'].'.yaml';
    Storage::disk('workflow-drivers')->put($path, Yaml::dump($definition, 10));
}

function allowWorkflowCatalogAccount(): void
{
    app()->bind(WorkflowAccessPolicy::class, fn () => new class implements WorkflowAccessPolicy
    {
        public function allows(WorkflowContext $context, string $id, string $version): bool
        {
            return $context->accountId === 'account-1';
        }
    });
}

test('workflow discovery defaults to deny and legacy driver loading remains available', function () {
    $definition = workflowCatalogFixture();
    writeWorkflowCatalogFixture($definition);
    unset($definition['workflow']);
    $definition['driver']['id'] = 'legacy';
    writeWorkflowCatalogFixture($definition);

    expect(app(WorkflowCatalog::class)->available($this->context))->toBe([])
        ->and(app(DriverService::class)->load('legacy', '1.0.0')->id)->toBe('legacy');
});

test('workflow catalog supports both plans and reviewed submissions without provider calls', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture());
    $reviewed = workflowCatalogFixture('philhealth.bst.demo');
    $reviewed['workflow'] = [
        'service' => 'philhealth', 'title' => 'Submit demo benefit claim',
        'entry_methods' => ['public_endpoint'], 'requires_review' => true,
    ];
    writeWorkflowCatalogFixture($reviewed);

    $catalog = app(WorkflowCatalog::class);
    expect($catalog->available($this->context))->toHaveCount(2);
    $purchase = $catalog->resolve('aui.purchase', '1.0.0', $this->context);
    $claim = $catalog->resolve('philhealth.bst.demo', '1.0.0', $this->context);
    expect($purchase->workflow->plans)->toHaveCount(1)
        ->and($claim->workflow->plans)->toHaveCount(0)
        ->and($claim->workflow->requires_review)->toBeTrue()
        ->and($claim->readiness->configured)->toBeTrue()
        ->and($purchase->readiness->configured)->toBeFalse();
    Http::assertNothingSent();
});

test('workflow resolution is account scoped and exact with no latest fallback', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture('aui.purchase', '2.0.0'));
    $catalog = app(WorkflowCatalog::class);
    expect($catalog->available(new WorkflowContext('actor-2', 'account-2')))->toBe([]);
    expect(fn () => $catalog->resolve('aui.purchase', '2.0.0', new WorkflowContext('actor-2', 'account-2')))->toThrow(DriverNotFoundException::class);
    expect(fn () => $catalog->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
    expect(fn () => $catalog->resolve('../aui.purchase', '2.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow catalog rejects inconsistent file identity', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture('another.driver'), 'aui.purchase/v1.0.0.yaml');
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow definition rejects unsafe or ambiguous values', function (string $field, mixed $value) {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    data_set($definition, 'workflow.'.$field, $value);
    writeWorkflowCatalogFixture($definition);
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
})->with([
    ['entry_methods', ['unknown']],
    ['entry_methods', []],
    ['connection', 'https://secret.example/token'],
    ['plans.0.premium_minor', -1],
    ['plans.0.premium_minor', 12.5],
    ['plans.0.currency', 'pesos'],
    ['requires_review', 'false'],
    ['unexpected_token', 'must-not-be-accepted'],
]);

test('workflow inheritance requires pinned parents rather than silently choosing latest', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture('base', '2.0.0'));
    $child = workflowCatalogFixture('child');
    $child['extends'] = ['base@1.0.0'];
    writeWorkflowCatalogFixture($child);
    expect(fn () => app(WorkflowCatalog::class)->resolve('child', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
    $child['extends'] = ['base'];
    writeWorkflowCatalogFixture($child);
    expect(fn () => app(WorkflowCatalog::class)->resolve('child', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow readiness only inspects private connection config and returns no secrets', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture());
    config()->set('settlement-envelope.connections.aui-demo', [
        'driver' => 'http', 'base_url' => 'https://private-api.example.invalid/v1',
        'auth' => ['type' => 'bearer', 'token' => 'synthetic-test-secret'],
        'connect_timeout' => 5, 'timeout' => 15,
    ]);
    $descriptor = app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context);
    expect($descriptor->readiness->configured)->toBeTrue()
        ->and($descriptor->readiness->reason)->toBeNull();
    $serialized = json_encode($descriptor->toArray(), JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('synthetic-test-secret', 'private-api.example.invalid', 'base_url', 'auth');
    Http::assertNothingSent();
});

test('workflow readiness rejects invalid connection settings without revealing details', function (array $connection) {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture());
    config()->set('settlement-envelope.connections.aui-demo', $connection);
    $descriptor = app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context);
    expect($descriptor->readiness->configured)->toBeFalse()
        ->and($descriptor->readiness->reason)->toBe('connection_invalid');
    Http::assertNothingSent();
})->with([
    [[]],
    [['driver' => 'http', 'base_url' => 'http://example.invalid', 'auth' => ['type' => 'none'], 'connect_timeout' => 5, 'timeout' => 15]],
    [['driver' => 'http', 'base_url' => 'https://user:password@example.invalid', 'auth' => ['type' => 'none'], 'connect_timeout' => 5, 'timeout' => 15]],
    [['driver' => 'http', 'base_url' => 'https://example.invalid', 'auth' => ['type' => 'bearer', 'token' => ''], 'connect_timeout' => 5, 'timeout' => 15]],
    [['driver' => 'http', 'base_url' => 'https://example.invalid', 'auth' => ['type' => 'none'], 'connect_timeout' => 5, 'timeout' => 0]],
]);

test('workflow descriptor exposes typed requirements and declared messages without evaluating gates', function () {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    $definition['documents']['registry'] = [['type' => 'REGISTRATION', 'title' => 'Registration', 'allowed_mimes' => ['application/pdf'], 'max_size_mb' => 5]];
    $definition['checklist']['template'] = [['key' => 'registration', 'label' => 'Upload registration', 'kind' => 'document', 'doc_type' => 'REGISTRATION', 'required' => true, 'review' => 'none']];
    $definition['gates']['definitions'] = [['key' => 'application_ready', 'rule' => 'checklist.required_accepted']];
    $definition['workflow']['notifications'] = [['event' => 'payment_received', 'sms' => 'Continue: {claim_url}', 'placeholders' => ['claim_url'], 'allow_override' => false]];
    writeWorkflowCatalogFixture($definition);
    $descriptor = app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context);
    expect($descriptor->documents[0]->type)->toBe('REGISTRATION')
        ->and($descriptor->checklist[0]->required)->toBeTrue()
        ->and($descriptor->gates)->toBe(['application_ready'])
        ->and($descriptor->workflow->notifications[0]->sms)->toBe('Continue: {claim_url}');
    expect(Envelope::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('workflow definition rejects undeclared notification placeholders', function () {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    $definition['workflow']['notifications'] = [['event' => 'payment_received', 'sms' => 'Secret: {token}', 'placeholders' => ['claim_url']]];
    writeWorkflowCatalogFixture($definition);
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow discovery does not reuse stale cached definitions after revision edits', function () {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    writeWorkflowCatalogFixture($definition);
    $catalog = app(WorkflowCatalog::class);
    expect($catalog->resolve('aui.purchase', '1.0.0', $this->context)->workflow->title)->toBe('Purchase demonstration cover');
    $definition['workflow']['title'] = 'Changed draft title';
    writeWorkflowCatalogFixture($definition);
    expect($catalog->resolve('aui.purchase', '1.0.0', $this->context)->workflow->title)->toBe('Changed draft title');
});

test('workflow discovery reads flat file versions and ignores legacy opt out definitions', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture('aui.purchase', '1.1.0'), 'aui.purchase.yaml');
    Storage::disk('workflow-drivers')->put('legacy-bst.yaml', Yaml::dump(['id' => 'legacy-bst', 'schema' => ['payload' => ['required' => true]]]));
    $descriptors = app(WorkflowCatalog::class)->available($this->context);
    expect($descriptors)->toHaveCount(1)
        ->and($descriptors[0]->version)->toBe('1.1.0');
});

test('workflow composition inherits requirements but replaces plan lists explicitly', function () {
    allowWorkflowCatalogAccount();
    $parent = workflowCatalogFixture('base');
    $parent['documents']['registry'] = [['type' => 'ID', 'title' => 'Identity document']];
    writeWorkflowCatalogFixture($parent);
    $child = workflowCatalogFixture('child');
    unset($child['workflow'], $child['documents']);
    $child['workflow'] = [];
    $child['extends'] = ['base@1.0.0'];
    writeWorkflowCatalogFixture($child);
    $catalog = app(WorkflowCatalog::class);
    $inherited = $catalog->resolve('child', '1.0.0', $this->context);
    expect($inherited->workflow->plans)->toHaveCount(1)
        ->and($inherited->documents[0]->type)->toBe('ID');
    $child['workflow'] = ['plans' => []];
    writeWorkflowCatalogFixture($child);
    expect($catalog->resolve('child', '1.0.0', $this->context)->workflow->plans)->toHaveCount(0);
});

test('workflow rejects circular inheritance', function () {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    $definition['extends'] = ['aui.purchase@1.0.0'];
    writeWorkflowCatalogFixture($definition);
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow plan coverage is explicit and typed', function () {
    allowWorkflowCatalogAccount();
    $definition = workflowCatalogFixture();
    $definition['workflow']['plans'][0]['coverage'] = ['basis' => 'day', 'duration_days' => 1];
    writeWorkflowCatalogFixture($definition);
    expect(app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context)->workflow->plans[0]->coverage->duration_days)->toBe(1);
    $definition['workflow']['plans'][0]['coverage'] = ['basis' => 'trip'];
    writeWorkflowCatalogFixture($definition);
    expect(fn () => app(WorkflowCatalog::class)->resolve('aui.purchase', '1.0.0', $this->context))->toThrow(InvalidDriverException::class);
});

test('workflow discovery leaves legacy unpinned inheritance out of the catalog', function () {
    allowWorkflowCatalogAccount();
    writeWorkflowCatalogFixture(workflowCatalogFixture());
    $legacy = workflowCatalogFixture('legacy.child');
    unset($legacy['workflow']);
    $legacy['extends'] = ['aui.purchase'];
    writeWorkflowCatalogFixture($legacy);
    $catalog = app(WorkflowCatalog::class);
    expect($catalog->available($this->context))->toHaveCount(1);
    expect(fn () => $catalog->resolve('legacy.child', '1.0.0', $this->context))->toThrow(DriverNotFoundException::class);
});

test('workflow catalog supports always validated nested data and composition', function () {
    config()->set('data.validation_strategy', ValidationStrategy::Always->value);
    allowWorkflowCatalogAccount();
    $parent = workflowCatalogFixture('validated.base');
    $parent['workflow']['plans'][0]['coverage'] = ['basis' => 'day', 'duration_days' => 1];
    $parent['workflow']['notifications'] = [[
        'event' => 'payment_received', 'sms' => 'Continue: {claim_url}',
        'placeholders' => ['claim_url'], 'allow_override' => false,
    ]];
    writeWorkflowCatalogFixture($parent);
    $child = workflowCatalogFixture('validated.child');
    $child['extends'] = ['validated.base@1.0.0'];
    $child['workflow'] = ['title' => 'Composed validated workflow'];
    writeWorkflowCatalogFixture($child);

    $catalog = app(WorkflowCatalog::class);
    expect($catalog->available($this->context))->toHaveCount(2);
    foreach (['validated.base', 'validated.child'] as $id) {
        $descriptor = $catalog->resolve($id, '1.0.0', $this->context);
        expect($descriptor->workflow->plans[0]->code)->toBe('PA5000_DAY')
            ->and($descriptor->workflow->plans[0]->coverage->duration_days)->toBe(1)
            ->and($descriptor->workflow->notifications[0]->sms)->toBe('Continue: {claim_url}')
            ->and($descriptor->workflow->entry_methods[0])->toBe(WorkflowEntryMethod::PaymentQr);
    }
    expect($catalog->resolve('validated.child', '1.0.0', $this->context)->workflow->title)->toBe('Composed validated workflow');
    Http::assertNothingSent();
});
