<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->fixturePath = __DIR__.'/../Fixtures/drivers/philhealth-bst.yaml';
    $this->legacy = Yaml::parseFile($this->fixturePath);
});

describe('legacy philhealth bst normalizer scaffold', function () {
    test('fixture is available for normalization', function () {
        expect(file_exists($this->fixturePath))->toBeTrue()
            ->and($this->legacy)->toBeArray()
            ->and($this->legacy['id'] ?? null)->toBe('philhealth-bst');
    });

    test('can map legacy metadata into package driver metadata shape', function () {
        $normalized = [
            'driver' => [
                'id' => $this->legacy['id'] ?? null,
                'version' => $this->legacy['version'] ?? null,
                'title' => $this->legacy['name'] ?? null,
                'description' => $this->legacy['description'] ?? null,
                'domain' => 'healthcare',
                'issuer_type' => 'institution',
            ],
        ];

        expect($normalized['driver'])->toMatchArray([
            'id' => 'philhealth-bst',
            'version' => '1.0.0',
            'title' => 'PhilHealth BST Settlement',
        ]);
    });

    test('can map legacy payload schema into package payload shape', function () {
        $legacyFields = $this->legacy['schema']['payload']['fields'] ?? [];

        $properties = [];
        $required = [];

        foreach ($legacyFields as $field => $definition) {
            $type = match ($definition['type'] ?? 'string') {
                'date' => 'string',
                default => $definition['type'] ?? 'string',
            };

            $properties[$field] = [
                'type' => $type,
            ];

            if (($definition['type'] ?? null) === 'date') {
                $properties[$field]['format'] = 'date';
            }

            if (($definition['required'] ?? false) === true) {
                $required[] = $field;
            }
        }

        $normalized = [
            'payload' => [
                'schema' => [
                    'id' => 'philhealth-bst.v1.0.0',
                    'format' => 'json_schema',
                    'inline' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                    ],
                ],
                'storage' => [
                    'mode' => 'versioned',
                    'patch_strategy' => 'merge',
                ],
            ],
        ];

        expect($normalized['payload']['schema']['inline']['type'])->toBe('object')
            ->and($normalized['payload']['schema']['inline']['properties'])->toHaveKeys([
                'patient_name',
                'patient_mobile',
                'philhealth_number',
                'diagnosis',
                'hospital_name',
                'admission_date',
                'discharge_date',
            ])
            ->and($normalized['payload']['schema']['inline']['required'])->toContain('patient_name', 'patient_mobile');
    });

    test('can map legacy document schema into package documents registry shape', function () {
        $legacyDocuments = $this->legacy['schema']['documents'] ?? [];

        $registry = collect($legacyDocuments)
            ->map(function (array $definition, string $type) {
                return [
                    'type' => $type,
                    'title' => $definition['name'] ?? $type,
                    'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
                    'max_size_mb' => 10,
                    'multiple' => false,
                    'auto_accept' => $definition['auto_accept'] ?? false,
                    'required' => $definition['required'] ?? false,
                    'description' => $definition['description'] ?? null,
                ];
            })
            ->values()
            ->all();

        $normalized = [
            'documents' => [
                'registry' => $registry,
            ],
        ];

        expect($normalized['documents']['registry'])->toHaveCount(4);

        $types = collect($normalized['documents']['registry'])->pluck('type')->toArray();

        expect($types)->toContain(
            'CLAIM_FORM',
            'HOSPITAL_BILL',
            'DISCHARGE_SUMMARY',
            'VALID_ID',
        );
    });

    test('can map legacy checklist into package checklist template shape', function () {
        $legacyChecklist = $this->legacy['checklist'] ?? [];

        $template = collect($legacyChecklist)
            ->map(function (array $item) {
                return [
                    'key' => $item['id'] ?? null,
                    'label' => $item['label'] ?? null,
                    'kind' => 'attestation',
                    'required' => true,
                    'review' => ($item['auto'] ?? false) ? 'none' : 'required',
                    'description' => $item['description'] ?? null,
                ];
            })
            ->all();

        $normalized = [
            'checklist' => [
                'template' => $template,
            ],
        ];

        expect($normalized['checklist']['template'])->toHaveCount(3);

        $keys = collect($normalized['checklist']['template'])->pluck('key')->toArray();

        expect($keys)->toContain(
            'payload_present',
            'claim_documents_uploaded',
            'amount_verified',
        );
    });

    test('can derive signal definitions from legacy manual checklist items', function () {
        $legacyChecklist = $this->legacy['checklist'] ?? [];

        $signals = collect($legacyChecklist)
            ->filter(fn (array $item) => ($item['auto'] ?? false) === false)
            ->map(function (array $item) {
                return [
                    'key' => $item['id'],
                    'type' => 'boolean',
                    'source' => 'host',
                    'default' => false,
                    'required' => true,
                ];
            })
            ->values()
            ->all();

        $normalized = [
            'signals' => [
                'definitions' => $signals,
            ],
        ];

        expect($normalized['signals']['definitions'])->toHaveCount(1)
            ->and($normalized['signals']['definitions'][0])->toMatchArray([
                'key' => 'amount_verified',
                'type' => 'boolean',
                'source' => 'host',
                'default' => false,
                'required' => true,
            ]);
    });

    test('can map legacy gates into package gate definitions shape', function () {
        $legacyGateConditions = $this->legacy['gates']['settleable']['conditions'] ?? [];

        $rule = collect($legacyGateConditions)
            ->map(fn (string $condition) => "signal.{$condition}")
            ->implode(' && ');

        $normalized = [
            'gates' => [
                'definitions' => [
                    [
                        'key' => 'settleable',
                        'rule' => $rule,
                    ],
                ],
            ],
        ];

        expect($normalized['gates']['definitions'])->toHaveCount(1)
            ->and($normalized['gates']['definitions'][0]['key'])->toBe('settleable')
            ->and($normalized['gates']['definitions'][0]['rule'])->toBe('signal.payload_present && signal.amount_verified');
    });

    test('can preserve form flow mapping in package-compatible structure', function () {
        $normalized = [
            'form_flow_mapping' => [
                'payload' => $this->legacy['form_flow_mapping']['payload'] ?? [],
                'attachments' => [],
            ],
        ];

        expect($normalized['form_flow_mapping'])->toHaveKeys(['payload', 'attachments'])
            ->and($normalized['form_flow_mapping']['payload'])->toHaveKeys([
                'patient_name',
                'patient_mobile',
            ])
            ->and($normalized['form_flow_mapping']['attachments'])->toBeArray();
    });
});

describe('future normalizer contract', function () {
    test('normalizer should return package-compatible top-level structure', function () {
        $normalized = [
            'driver' => [
                'id' => $this->legacy['id'] ?? null,
                'version' => $this->legacy['version'] ?? null,
                'title' => $this->legacy['name'] ?? null,
                'description' => $this->legacy['description'] ?? null,
            ],
            'payload' => [],
            'documents' => ['registry' => []],
            'checklist' => ['template' => []],
            'signals' => ['definitions' => []],
            'gates' => ['definitions' => []],
            'form_flow_mapping' => [
                'payload' => [],
                'attachments' => [],
            ],
            'audit' => $this->legacy['audit'] ?? [],
        ];

        expect($normalized)->toHaveKeys([
            'driver',
            'payload',
            'documents',
            'checklist',
            'signals',
            'gates',
            'form_flow_mapping',
            'audit',
        ]);
    });

    test('normalizer class can be implemented later', function () {
        expect(class_exists(\LBHurtado\SettlementEnvelope\Support\LegacyPhilhealthBstNormalizer::class))
            ->toBeFalse();
    });

    test('normalizer can eventually produce data consumable by DriverService::parseDriver', function () {
        $normalized = [
            'driver' => [
                'id' => 'philhealth-bst',
                'version' => '1.0.0',
                'title' => 'PhilHealth BST Settlement',
                'description' => $this->legacy['description'] ?? null,
            ],
            'payload' => [
                'schema' => [
                    'id' => 'philhealth-bst.v1.0.0',
                    'format' => 'json_schema',
                    'inline' => [
                        'type' => 'object',
                        'properties' => [],
                        'required' => [],
                    ],
                ],
                'storage' => [
                    'mode' => 'versioned',
                    'patch_strategy' => 'merge',
                ],
            ],
            'documents' => [
                'registry' => [],
            ],
            'checklist' => [
                'template' => [],
            ],
            'signals' => [
                'definitions' => [],
            ],
            'gates' => [
                'definitions' => [],
            ],
        ];

        expect($normalized)->toHaveKey('driver')
            ->and($normalized['payload'])->toHaveKeys(['schema', 'storage'])
            ->and($normalized['documents'])->toHaveKey('registry')
            ->and($normalized['checklist'])->toHaveKey('template')
            ->and($normalized['signals'])->toHaveKey('definitions')
            ->and($normalized['gates'])->toHaveKey('definitions');
    });
});

describe('future end-to-end normalization', function () {
    test('can be normalized by dedicated normalizer later', function () {
        test()->markTestSkipped('Enable when LegacyPhilhealthBstNormalizer is implemented.');
    });
});