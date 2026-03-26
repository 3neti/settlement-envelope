<?php

use LBHurtado\SettlementEnvelope\Data\DriverData;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;
use LBHurtado\SettlementEnvelope\Services\DriverService;

beforeEach(function () {
    $this->driverDirectory = __DIR__.'/../../drivers';
    $this->service = new DriverService($this->driverDirectory);
});

describe('driver loading', function () {
    test('loads simple test driver', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver)->toBeInstanceOf(DriverData::class)
            ->and($driver->id)->toBe('simple.test')
            ->and($driver->version)->toBe('1.0.0')
            ->and($driver->title)->toBe('Simple Test Driver')
            ->and($driver->domain)->toBe('testing');
    });

    test('loads bank home-loan-takeout driver', function () {
        $driver = $this->service->load('bank.home-loan-takeout', '1.0.0');

        expect($driver)->toBeInstanceOf(DriverData::class)
            ->and($driver->id)->toBe('bank.home-loan-takeout')
            ->and($driver->domain)->toBe('housing_finance')
            ->and($driver->issuer_type)->toBe('developer');
    });

    test('throws exception for non-existent driver', function () {
        expect(fn () => $this->service->load('non.existent'))
            ->toThrow(DriverNotFoundException::class);
    });

    test('caches loaded drivers in memory', function () {
        $driver1 = $this->service->load('simple.test', '1.0.0');
        $driver2 = $this->service->load('simple.test', '1.0.0');

        // Should be same instance from memory cache
        expect($driver1)->toBe($driver2);
    });
});

describe('driver parsing - payload config', function () {
    test('parses payload schema config', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->payload)->not->toBeNull()
            ->and($driver->payload->schema->id)->toBe('simple.test.v1')
            ->and($driver->payload->schema->format)->toBe('json_schema');
    });

    test('parses inline schema', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $schema = $this->service->getSchema($driver);

        expect($schema)->not->toBeNull()
            ->and($schema['type'])->toBe('object')
            ->and($schema['required'])->toContain('name');
    });

    test('parses storage config', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->payload->storage->mode)->toBe('versioned')
            ->and($driver->payload->storage->patch_strategy)->toBe('merge');
    });
});

describe('driver parsing - documents', function () {
    test('parses document registry', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->documents)->toHaveCount(1);

        $doc = $driver->documents[0];
        expect($doc->type)->toBe('TEST_DOC')
            ->and($doc->title)->toBe('Test Document')
            ->and($doc->max_size_mb)->toBe(5)
            ->and($doc->multiple)->toBeFalse();
    });

    test('parses allowed mimes', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $doc = $driver->documents[0];
        expect($doc->allowed_mimes)->toContain('application/pdf', 'image/jpeg', 'image/png');
    });

    test('getDocumentType helper works', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $doc = $driver->getDocumentType('TEST_DOC');
        expect($doc)->not->toBeNull()
            ->and($doc->type)->toBe('TEST_DOC');

        $missing = $driver->getDocumentType('NON_EXISTENT');
        expect($missing)->toBeNull();
    });
});

describe('driver parsing - checklist', function () {
    test('parses checklist template', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->checklist)->toHaveCount(3);
    });

    test('parses payload_field checklist item', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $item = $driver->getChecklistItem('name_provided');
        expect($item)->not->toBeNull()
            ->and($item->kind)->toBe('payload_field')
            ->and($item->payload_pointer)->toBe('/name')
            ->and($item->required)->toBeTrue();
    });

    test('parses document checklist item', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $item = $driver->getChecklistItem('test_doc');
        expect($item)->not->toBeNull()
            ->and($item->kind)->toBe('document')
            ->and($item->doc_type)->toBe('TEST_DOC')
            ->and($item->review)->toBe('required');
    });

    test('parses signal checklist item', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $item = $driver->getChecklistItem('approved_signal');
        expect($item)->not->toBeNull()
            ->and($item->kind)->toBe('signal')
            ->and($item->signal_key)->toBe('approved');
    });
});

describe('driver parsing - signals', function () {
    test('parses signal definitions', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->signals)->toHaveCount(1);

        $signal = $driver->signals[0];
        expect($signal->key)->toBe('approved')
            ->and($signal->type)->toBe('boolean')
            ->and($signal->source)->toBe('host')
            ->and($signal->default)->toBeFalse();
    });

    test('getSignalDefinition helper works', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $signal = $driver->getSignalDefinition('approved');
        expect($signal)->not->toBeNull()
            ->and($signal->key)->toBe('approved');

        $missing = $driver->getSignalDefinition('non_existent');
        expect($missing)->toBeNull();
    });

    test('parses multiple signals', function () {
        $driver = $this->service->load('bank.home-loan-takeout', '1.0.0');

        expect($driver->signals)->toHaveCount(3);

        $keys = collect($driver->signals)->pluck('key')->toArray();
        expect($keys)->toContain('kyc_passed', 'account_created', 'underwriting_approved');
    });
});

describe('driver parsing - gates', function () {
    test('parses gate definitions', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->gates)->toHaveCount(3);
    });

    test('parses gate rule', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        $gate = $driver->getGateDefinition('settleable');
        expect($gate)->not->toBeNull()
            ->and($gate->rule)->toBe('gate.payload_valid && gate.checklist_complete && signal.approved');
    });

    test('parses complex gate hierarchy', function () {
        $driver = $this->service->load('bank.home-loan-takeout', '1.0.0');

        expect($driver->gates)->toHaveCount(5);

        $settleableGate = $driver->getGateDefinition('settleable');
        expect($settleableGate->rule)->toContain('gate.evidence_ready')
            ->and($settleableGate->rule)->toContain('gate.account_ready')
            ->and($settleableGate->rule)->toContain('signal.underwriting_approved');
    });
});

describe('driver listing', function () {
    test('lists available drivers', function () {
        $drivers = $this->service->list();

        expect($drivers)->toBeArray()
            ->and(count($drivers))->toBeGreaterThanOrEqual(2);

        $ids = collect($drivers)->pluck('id')->toArray();
        expect($ids)->toContain('simple.test', 'bank.home-loan-takeout');
    });
});

describe('getDriverKey', function () {
    test('returns combined driver key', function () {
        $driver = $this->service->load('simple.test', '1.0.0');

        expect($driver->getDriverKey())->toBe('simple.test@1.0.0');
    });
});
