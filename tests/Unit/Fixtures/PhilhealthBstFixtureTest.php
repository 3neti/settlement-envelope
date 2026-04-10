<?php

use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;
use LBHurtado\SettlementEnvelope\Services\DriverService;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->fixturePath = __DIR__.'/../../Fixtures/drivers/philhealth-bst.yaml';

    // Separate disk dedicated to fixture tests
    config()->set('filesystems.disks.fixture-drivers', [
        'driver' => 'local',
        'root' => __DIR__.'/../../Fixtures/drivers',
        'throw' => false,
    ]);

    $this->service = new DriverService('fixture-drivers');
});

describe('fixture presence', function () {
    test('philhealth bst fixture file exists', function () {
        expect(file_exists($this->fixturePath))->toBeTrue();
    });

    test('philhealth bst fixture is readable', function () {
        expect(is_readable($this->fixturePath))->toBeTrue();
    });

    test('philhealth bst fixture is valid yaml', function () {
        $parsed = Yaml::parseFile($this->fixturePath);

        expect($parsed)->toBeArray();
    });
});

describe('legacy host-app fixture structure', function () {
    test('contains expected top-level metadata fields', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data)->toHaveKeys([
            'id',
            'version',
            'name',
            'description',
            'schema',
            'checklist',
            'gates',
            'form_flow_mapping',
            'audit',
        ]);

        expect($data['id'])->toBe('philhealth-bst')
            ->and($data['version'])->toBe('1.0.0')
            ->and($data['name'])->toBe('PhilHealth BST Settlement');
    });

    test('contains payload schema fields', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['schema'])->toHaveKey('payload')
            ->and($data['schema']['payload'])->toHaveKeys(['required', 'fields']);

        $fields = $data['schema']['payload']['fields'];

        expect($fields)->toHaveKeys([
            'patient_name',
            'patient_mobile',
            'philhealth_number',
            'diagnosis',
            'hospital_name',
            'admission_date',
            'discharge_date',
        ]);

        expect($fields['patient_name'])->toHaveKeys(['type', 'required', 'label'])
            ->and($fields['patient_name']['type'])->toBe('string')
            ->and($fields['patient_name']['required'])->toBeTrue();
    });

    test('contains document schema entries', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['schema'])->toHaveKey('documents');

        $documents = $data['schema']['documents'];

        expect($documents)->toHaveKeys([
            'CLAIM_FORM',
            'HOSPITAL_BILL',
            'DISCHARGE_SUMMARY',
            'VALID_ID',
        ]);

        expect($documents['VALID_ID'])->toHaveKeys(['name', 'required', 'auto_accept'])
            ->and($documents['VALID_ID']['auto_accept'])->toBeTrue();
    });

    test('contains expected checklist items', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['checklist'])->toBeArray()
            ->and($data['checklist'])->toHaveCount(3);

        $ids = collect($data['checklist'])->pluck('id')->toArray();

        expect($ids)->toContain(
            'payload_present',
            'claim_documents_uploaded',
            'amount_verified',
        );
    });

    test('contains settleable gate definition', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['gates'])->toHaveKey('settleable')
            ->and($data['gates']['settleable'])->toHaveKey('conditions');

        expect($data['gates']['settleable']['conditions'])->toContain(
            'payload_present',
            'amount_verified',
        );
    });

    test('contains form flow mapping entries', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['form_flow_mapping'])->toHaveKey('payload');

        $payloadMapping = $data['form_flow_mapping']['payload'];

        expect($payloadMapping)->toHaveKeys([
            'patient_name',
            'patient_mobile',
        ]);

        expect($payloadMapping['patient_name'])->toBe('bio_fields.name | bio_fields.full_name')
            ->and($payloadMapping['patient_mobile'])->toBe('wallet_info.mobile');
    });

    test('contains audit settings', function () {
        $data = Yaml::parseFile($this->fixturePath);

        expect($data['audit'])->toHaveKeys([
            'capture_all',
            'retention_days',
        ]);

        expect($data['audit']['capture_all'])->toBeTrue()
            ->and($data['audit']['retention_days'])->toBe(2555);
    });
});

describe('compatibility with current package driver parser', function () {
    test('legacy philhealth bst fixture is currently not parseable by DriverService', function () {
        expect(fn () => $this->service->load('philhealth-bst', '1.0.0'))
            ->toThrow(InvalidDriverException::class, "missing 'driver' key");
    });
});

describe('legacy fixture intent checks', function () {
    test('all checklist items have required legacy fields', function () {
        $data = Yaml::parseFile($this->fixturePath);

        foreach ($data['checklist'] as $item) {
            expect($item)->toHaveKeys(['id', 'label', 'auto', 'description']);
        }
    });

    test('all payload field definitions declare a type', function () {
        $data = Yaml::parseFile($this->fixturePath);

        foreach ($data['schema']['payload']['fields'] as $field => $definition) {
            expect($definition)->toHaveKey('type');
        }
    });

    test('all document definitions declare required and auto_accept flags', function () {
        $data = Yaml::parseFile($this->fixturePath);

        foreach ($data['schema']['documents'] as $documentType => $definition) {
            expect($definition)->toHaveKeys(['name', 'required', 'auto_accept']);
        }
    });

    test('settleable gate conditions reference checklist ids', function () {
        $data = Yaml::parseFile($this->fixturePath);

        $checklistIds = collect($data['checklist'])->pluck('id')->toArray();
        $conditions = $data['gates']['settleable']['conditions'] ?? [];

        foreach ($conditions as $condition) {
            expect($checklistIds)->toContain($condition);
        }
    });
});

describe('future migration target', function () {
    test('fixture can be normalized into package driver shape later', function () {
        $data = Yaml::parseFile($this->fixturePath);

        // This is intentionally a scaffold target for future migration/adaptation.
        // It documents the minimum expected output shape once a normalizer exists.
        $normalized = [
            'driver' => [
                'id' => $data['id'] ?? null,
                'version' => $data['version'] ?? null,
                'title' => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
            ],
        ];

        expect($normalized)->toHaveKey('driver')
            ->and($normalized['driver']['id'])->toBe('philhealth-bst')
            ->and($normalized['driver']['version'])->toBe('1.0.0');
    })->skip('Enable when legacy philhealth-bst normalization is implemented.');
});