<?php

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use LBHurtado\SettlementEnvelope\Data\DriverData;
use LBHurtado\SettlementEnvelope\Data\FormFlowMappingData;
use LBHurtado\SettlementEnvelope\Exceptions\CircularDependencyException;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;
use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class DriverService
{
    protected array $loadedDrivers = [];

    public function __construct(
        protected ?string $driverDisk = null,
        protected ?DriverSourceRegistry $sources = null
    ) {
        $this->driverDisk = $driverDisk ?? config('settlement-envelope.driver_disk');
        $this->sources ??= app(DriverSourceRegistry::class);
    }

    /**
     * Get the storage disk instance
     */
    protected function disk(): Filesystem
    {
        return Storage::disk($this->driverDisk);
    }

    protected function readSource(string $source, string $path): string
    {
        if ($path === '' || str_contains($path, '..') || ! preg_match('~^[a-zA-Z0-9_./-]+$~D', $path) || str_starts_with($path, '/')) {
            throw new InvalidDriverException('Invalid driver resource path.');
        }
        $content = $source === 'host' ? $this->disk()->get($path) : $this->sources->read($source, $path);
        if (! is_string($content) || strlen($content) > 1048576) {
            throw new InvalidDriverException('Invalid or oversized driver resource.');
        }

        return $content;
    }

    /** @return list<array{id: string, version: string, path: string, source: string, data: array}> */
    protected function definitions(): array
    {
        $files = [];
        foreach ($this->sources->roots() as $source => $root) {
            foreach (glob($root.'/*/*.yaml') ?: [] as $path) {
                $files[] = ['id' => basename(dirname($path)), 'version' => substr(basename($path, '.yaml'), 1), 'path' => substr($path, strlen($root) + 1), 'source' => $source];
            }
        }
        foreach ($this->hostFiles() as $file) {
            $files[] = [...$file, 'source' => 'host'];
        }
        $definitions = [];
        foreach ($files as $file) {
            try {
                $data = Yaml::parse($this->readSource($file['source'], $file['path']));
            } catch (Throwable $exception) {
                throw new InvalidDriverException('Invalid driver YAML.', previous: $exception);
            }
            if (is_array($data) && $file['source'] === 'host' && ! isset($data['driver']) && ! array_key_exists('workflow', $data)) {
                if (isset($definitions[$file['id'].'@'.$file['version']])) {
                    throw new InvalidDriverException('Legacy host definition conflicts with a registered driver.');
                }
                $definitions[$file['id'].'@'.$file['version']] = [...$file, 'data' => $data];

                continue;
            }
            if (is_array($data) && $file['source'] === 'host' && ! array_key_exists('workflow', $data) && is_array($data['driver'] ?? null)) {
                $data['driver']['id'] ??= $file['id'];
                $data['driver']['version'] ??= '1.0.0';
            }
            $id = $data['driver']['id'] ?? null;
            $version = $data['driver']['version'] ?? null;
            if (! is_string($id) || ! is_string($version) || ! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $id) || strlen($id) > 100 || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?$/D', $version) || strlen($version) > 60 || $id !== $file['id'] || ($file['path'] !== $id.'.yaml' && $file['path'] !== $id.'/v'.$version.'.yaml')) {
                throw new InvalidDriverException('Exact driver identity does not match.');
            }
            $uri = $data['payload']['schema']['uri'] ?? null;
            if ($uri !== null && ! isset($data['payload']['schema']['inline'])) {
                if (! is_string($uri) || ! preg_match('~^[a-zA-Z0-9_][a-zA-Z0-9_./-]*$~D', $uri) || str_contains($uri, '..')) {
                    throw new InvalidDriverException('Invalid schema resource path.');
                }
                $schemaPath = $id.'/'.$uri;
                if ($file['source'] !== 'host' || $this->disk()->exists($schemaPath)) {
                    try {
                        $schema = json_decode($this->readSource($file['source'], $schemaPath), true, 512, JSON_THROW_ON_ERROR);
                    } catch (Throwable $exception) {
                        throw new InvalidDriverException('Invalid driver schema.', previous: $exception);
                    }
                    if (! is_array($schema)) {
                        throw new InvalidDriverException('Invalid driver schema.');
                    }
                    $data['payload']['schema']['inline'] = $schema;
                }
            }
            $key = $id.'@'.$version;
            if (isset($definitions[$key])) {
                if ($this->canonicalDefinition($definitions[$key]['data']) === $this->canonicalDefinition($data)) {
                    continue;
                }
                if ($file['source'] !== 'host' || ! in_array($key, config('settlement-envelope.driver_host_overrides', []), true)) {
                    throw new InvalidDriverException("Conflicting driver definition: {$key}");
                }
            }
            $definitions[$key] = [...$file, 'version' => $version, 'data' => $data];
        }
        ksort($definitions);

        return array_values($definitions);
    }

    protected function canonicalDefinition(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalDefinition($value);
            }
        }
        if (! array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    protected function definition(string $id, ?string $version): array
    {
        $matches = array_values(array_filter($this->definitions(), fn (array $entry): bool => $entry['id'] === $id && ($version === null || $entry['version'] === $version)));
        if ($matches === []) {
            throw new DriverNotFoundException('Exact driver version is unavailable.');
        }
        usort($matches, fn (array $left, array $right): int => version_compare($right['version'], $left['version']));

        return $matches[0];
    }

    public function loadExact(string $driverId, string $version, bool $requireWorkflow = false): DriverData
    {
        $data = $this->loadExactData($driverId, $version, requireWorkflow: $requireWorkflow);

        return $this->parseDriver($data, $driverId);
    }

    /** @return list<array{id: string, version: string}> */
    public function workflowReferences(): array
    {
        $references = [];
        foreach ($this->definitions() as $file) {
            $content = $this->readSource($file['source'], $file['path']);
            if (strlen($content) > 1048576) {
                throw new InvalidDriverException('Driver definition is too large.');
            }
            try {
                $data = Yaml::parse($content);
            } catch (Throwable) {
                throw new InvalidDriverException('Invalid driver YAML.');
            }
            if (! is_array($data) || ! array_key_exists('workflow', $data)) {
                continue;
            }
            $id = $data['driver']['id'] ?? null;
            $version = $data['driver']['version'] ?? null;
            if (! is_string($id) || ! is_string($version) || $id !== $file['id']
                || ($file['path'] !== $id.'.yaml' && $version !== $file['version'])) {
                throw new InvalidDriverException('Exact driver identity does not match.');
            }
            $references[$id.'@'.$version] = ['id' => $id, 'version' => $version];
        }

        return array_values($references);
    }

    protected function loadExactData(string $driverId, string $version, array $stack = [], bool $requireWorkflow = false): array
    {
        if (! preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $driverId) || strlen($driverId) > 100 || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-zA-Z0-9.-]+)?$/D', $version) || strlen($version) > 60) {
            throw new InvalidDriverException('Invalid exact driver reference.');
        }
        $key = $driverId.'@'.$version;
        if (in_array($key, $stack, true) || count($stack) >= 16) {
            throw new InvalidDriverException('Invalid workflow driver inheritance.');
        }
        $data = $this->definition($driverId, $version)['data'];
        if (! is_array($data) || ($data['driver']['id'] ?? null) !== $driverId || ($data['driver']['version'] ?? null) !== $version) {
            throw new InvalidDriverException('Exact driver identity does not match.');
        }
        if ($requireWorkflow && ! array_key_exists('workflow', $data)) {
            throw new DriverNotFoundException('Workflow is unavailable.');
        }
        $parents = $data['extends'] ?? [];
        if (! is_array($parents) || ! array_is_list($parents) || count($parents) > 8) {
            throw new InvalidDriverException('Invalid workflow driver inheritance.');
        }
        $merged = [];
        foreach ($parents as $parent) {
            if (! is_string($parent) || substr_count($parent, '@') !== 1) {
                throw new InvalidDriverException('Workflow parents require exact versions.');
            }
            [$parentId, $parentVersion] = explode('@', $parent);
            $merged = $this->mergeDrivers($merged, $this->loadExactData($parentId, $parentVersion, [...$stack, $key]));
        }
        unset($data['extends']);

        return $this->mergeDrivers($merged, $data);
    }

    /**
     * Load a driver by ID and optional version
     */
    public function load(string $driverId, ?string $version = null): DriverData
    {
        $cacheKey = "envelope_driver:{$driverId}:{$version}";
        if ($this->sources->roots() !== []) {
            $cacheKey .= ':'.hash('sha256', serialize([$this->driverDisk, $this->sources->roots(), $this->definitions()]));
        }

        // Check memory cache first
        if (isset($this->loadedDrivers[$cacheKey])) {
            return $this->loadedDrivers[$cacheKey];
        }

        // Check application cache
        $driver = Cache::get($cacheKey);

        if (is_array($driver)) {
            try {
                $driver = DriverData::from($driver);
            } catch (Throwable) {
                $driver = null;
            }
        }

        if (! $driver instanceof DriverData) {
            Cache::forget($cacheKey);
            $driver = $this->loadFromFile($driverId, $version);
            Cache::put($cacheKey, $driver->toArray(), 3600);
        }

        $this->loadedDrivers[$cacheKey] = $driver;

        return $driver;
    }

    /**
     * Load driver from YAML file
     */
    protected function loadFromFile(string $driverId, ?string $version = null): DriverData
    {
        $data = $this->definition($driverId, $version)['data'];

        // Resolve extends composition if present
        if (isset($data['extends'])) {
            $data = $this->resolveComposition($data, [$driverId]);
        }

        return $this->parseDriver($data, $driverId);
    }

    /**
     * Resolve driver composition via extends
     *
     * @param  array  $data  The overlay driver data
     * @param  array  $resolved  Stack of resolved driver IDs (for circular detection)
     * @return array Merged driver data
     */
    protected function resolveComposition(array $data, array $resolved = []): array
    {
        $extends = $data['extends'] ?? [];
        unset($data['extends']);

        if (empty($extends)) {
            return $data;
        }

        // Start with empty base
        $merged = [];

        // Process each parent in order
        foreach ($extends as $parentRef) {
            [$parentId, $parentVersion] = $this->parseDriverRef($parentRef);

            // Check for circular dependency
            if (in_array($parentId, $resolved)) {
                throw new CircularDependencyException(
                    'Circular dependency detected: '.implode(' -> ', [...$resolved, $parentId])
                );
            }

            // Load parent driver data (raw, not parsed)
            $parentData = $this->definition($parentId, $parentVersion)['data'];

            // Recursively resolve parent's extends
            if (isset($parentData['extends'])) {
                $parentData = $this->resolveComposition($parentData, [...$resolved, $parentId]);
            }

            // Merge parent into result
            $merged = $this->mergeDrivers($merged, $parentData);
        }

        // Finally merge the overlay on top
        return $this->mergeDrivers($merged, $data);
    }

    /**
     * Parse driver reference like "bank.home-loan.base@1.0.0"
     */
    protected function parseDriverRef(string $ref): array
    {
        if (str_contains($ref, '@')) {
            [$id, $version] = explode('@', $ref, 2);

            return [$id, $version];
        }

        return [$ref, null];
    }

    /**
     * Merge two driver data arrays
     * Later values override earlier ones for scalar fields
     * Arrays are merged by key for registry-style fields
     */
    protected function mergeDrivers(array $base, array $overlay): array
    {
        // If base is empty, return overlay
        if (empty($base)) {
            return $overlay;
        }

        $result = $base;

        if (array_key_exists('workflow', $overlay)) {
            $result['workflow'] = is_array($overlay['workflow']) && is_array($base['workflow'] ?? null)
                ? array_merge($base['workflow'], $overlay['workflow'])
                : $overlay['workflow'];
        }

        // Merge driver metadata (overlay wins)
        if (isset($overlay['driver'])) {
            $result['driver'] = array_merge($result['driver'] ?? [], $overlay['driver']);
        }

        // Merge payload (overlay wins, but merge schema if both have inline)
        if (isset($overlay['payload'])) {
            $result['payload'] = array_merge($result['payload'] ?? [], $overlay['payload']);
        }

        // Merge documents registry by type
        if (isset($overlay['documents']['registry'])) {
            $result['documents']['registry'] = $this->mergeByKey(
                $result['documents']['registry'] ?? [],
                $overlay['documents']['registry'],
                'type'
            );
        }

        // Merge checklist template by key
        if (isset($overlay['checklist']['template'])) {
            $result['checklist']['template'] = $this->mergeByKey(
                $result['checklist']['template'] ?? [],
                $overlay['checklist']['template'],
                'key'
            );
        }

        // Merge signals definitions by key
        if (isset($overlay['signals']['definitions'])) {
            $result['signals']['definitions'] = $this->mergeByKey(
                $result['signals']['definitions'] ?? [],
                $overlay['signals']['definitions'],
                'key'
            );
        }

        // Merge gates definitions by key
        if (isset($overlay['gates']['definitions'])) {
            $result['gates']['definitions'] = $this->mergeByKey(
                $result['gates']['definitions'] ?? [],
                $overlay['gates']['definitions'],
                'key'
            );
        }

        // Merge other config sections (overlay wins)
        foreach (['audit', 'manifest', 'permissions', 'ui', 'scanner'] as $section) {
            if (isset($overlay[$section])) {
                $result[$section] = array_merge($result[$section] ?? [], $overlay[$section]);
            }
        }

        // Merge form_flow_mapping (deep merge payload sections, merge attachments by doc_type)
        if (isset($overlay['form_flow_mapping'])) {
            $baseMapping = $result['form_flow_mapping'] ?? [];
            $overlayMapping = $overlay['form_flow_mapping'];

            // Deep merge payload sections
            $mergedPayload = $baseMapping['payload'] ?? [];
            foreach ($overlayMapping['payload'] ?? [] as $section => $fields) {
                $mergedPayload[$section] = array_merge(
                    $mergedPayload[$section] ?? [],
                    $fields
                );
            }

            // Merge attachments by doc_type key (overlay wins)
            $mergedAttachments = $baseMapping['attachments'] ?? [];
            foreach ($overlayMapping['attachments'] ?? [] as $docType => $config) {
                $mergedAttachments[$docType] = $config;
            }

            $result['form_flow_mapping'] = [
                'payload' => $mergedPayload,
                'attachments' => $mergedAttachments,
            ];
        }

        return $result;
    }

    /**
     * Merge two arrays by a key field (union, later overrides)
     */
    protected function mergeByKey(array $base, array $overlay, string $keyField): array
    {
        $indexed = [];

        // Index base items
        foreach ($base as $item) {
            if (isset($item[$keyField])) {
                $indexed[$item[$keyField]] = $item;
            }
        }

        // Overlay items override or add
        foreach ($overlay as $item) {
            if (isset($item[$keyField])) {
                $indexed[$item[$keyField]] = $item;
            }
        }

        return array_values($indexed);
    }

    /**
     * Resolve driver file path (relative to disk root)
     */
    protected function resolveDriverPath(string $driverId, ?string $version = null): string
    {
        // Try versioned path first: {driverId}/v{version}.yaml
        if ($version) {
            $versionedPath = "{$driverId}/v{$version}.yaml";
            if ($this->disk()->exists($versionedPath)) {
                return $versionedPath;
            }
        }

        // Try latest version in directory
        $files = $this->disk()->files($driverId);
        $versionFiles = array_filter($files, fn ($f) => preg_match('/v[\d.]+\.yaml$/', $f));
        if (! empty($versionFiles)) {
            // Sort and get latest version
            usort($versionFiles, 'version_compare');

            return end($versionFiles);
        }

        // Try flat file: {driverId}.yaml
        $flatPath = "{$driverId}.yaml";
        if ($this->disk()->exists($flatPath)) {
            return $flatPath;
        }

        throw new DriverNotFoundException("Driver not found: {$driverId}");
    }

    /**
     * Parse raw YAML data into DriverData
     */
    protected function parseDriver(array $data, string $driverId): DriverData
    {
        if (! isset($data['driver'])) {
            throw new InvalidDriverException("Invalid driver format: missing 'driver' key");
        }

        $driver = $data['driver'];

        return DriverData::from([
            'id' => $driver['id'] ?? $driverId,
            'version' => $driver['version'] ?? '1.0.0',
            'title' => $driver['title'] ?? $driverId,
            'description' => $driver['description'] ?? null,
            'domain' => $driver['domain'] ?? null,
            'issuer_type' => $driver['issuer_type'] ?? null,
            'payload' => $this->parsePayloadConfig($data['payload'] ?? []),
            'documents' => $this->parseDocuments($data['documents']['registry'] ?? []),
            'checklist' => $data['checklist']['template'] ?? [],
            'signals' => $data['signals']['definitions'] ?? [],
            'gates' => $data['gates']['definitions'] ?? [],
            'permissions' => $data['permissions'] ?? null,
            'audit' => $data['audit'] ?? null,
            'manifest' => $data['manifest'] ?? null,
            'ui' => $data['ui'] ?? null,
            'form_flow_mapping' => FormFlowMappingData::fromArray($data['form_flow_mapping'] ?? null),
            'scanner' => $data['scanner'] ?? null,
            'workflow' => array_key_exists('workflow', $data)
                ? (new WorkflowDefinitionParser)->parse($data['workflow'])->toArray()
                : null,
        ]);
    }

    protected function parsePayloadConfig(array $config): array
    {
        $parsed = [
            'schema' => [
                'id' => $config['schema']['id'] ?? 'default',
                'format' => $config['schema']['format'] ?? 'json_schema',
                'uri' => $config['schema']['uri'] ?? null,
                'inline' => $config['schema']['inline'] ?? null,
            ],
            'storage' => [
                'mode' => $config['storage']['mode'] ?? 'versioned',
                'patch_strategy' => $config['storage']['patch_strategy'] ?? 'merge',
            ],
        ];

        // Parse constraints if present
        if (isset($config['constraints'])) {
            $parsed['constraints'] = [
                'unique_fields' => $config['constraints']['unique_fields'] ?? [],
            ];
        }

        return $parsed;
    }

    protected function parseDocuments(array $registry): array
    {
        return array_map(function ($doc) {
            return [
                'type' => $doc['type'],
                'title' => $doc['title'] ?? $doc['type'],
                'allowed_mimes' => $doc['allowed_mimes'] ?? ['application/pdf', 'image/jpeg', 'image/png'],
                'max_size_mb' => $doc['max_size_mb'] ?? 10,
                'multiple' => $doc['multiple'] ?? false,
            ];
        }, $registry);
    }

    /**
     * Get schema content for a driver
     */
    public function getSchema(DriverData $driver): ?array
    {
        $schemaConfig = $driver->payload->schema;

        // Inline schema
        if ($schemaConfig->inline) {
            return $schemaConfig->inline;
        }

        // External schema file
        if ($schemaConfig->uri) {
            $schemaPath = $driver->id.'/'.$schemaConfig->uri;
            if ($this->disk()->exists($schemaPath)) {
                return json_decode($this->readSource('host', $schemaPath), true);
            }
        }

        return null;
    }

    /**
     * List available drivers
     */
    public function list(): array
    {
        return array_map(fn (array $entry): array => array_intersect_key($entry, array_flip(['id', 'version', 'path', 'source'])), $this->definitions());
    }

    protected function hostFiles(): array
    {
        $drivers = [];

        // Find all driver directories
        $directories = $this->disk()->directories();
        foreach ($directories as $driverId) {
            $files = $this->disk()->files($driverId);
            foreach ($files as $file) {
                if (preg_match('/v(.+)\.yaml$/', basename($file), $matches)) {
                    $version = $matches[1];
                    $drivers[] = [
                        'id' => $driverId,
                        'version' => $version,
                        'path' => $file,
                    ];
                }
            }
        }

        // Find flat driver files in root
        $rootFiles = $this->disk()->files();
        foreach ($rootFiles as $file) {
            if (str_ends_with($file, '.yaml')) {
                $driverId = basename($file, '.yaml');
                $drivers[] = [
                    'id' => $driverId,
                    'version' => '1.0.0',
                    'path' => $file,
                ];
            }
        }

        return $drivers;
    }

    /**
     * Clear driver cache
     */
    public function clearCache(?string $driverId = null): void
    {
        if ($driverId) {
            Cache::forget("envelope_driver:{$driverId}:*");
        }

        $this->loadedDrivers = [];
    }

    /**
     * Write a driver to YAML file
     */
    public function write(string $driverId, string $version, array $data): string
    {
        $filePath = "{$driverId}/v{$version}.yaml";

        // Convert to YAML
        $yaml = Yaml::dump($data, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        // Write file (Storage creates directories automatically)
        $this->disk()->put($filePath, $yaml);

        // Clear cache for this driver
        $this->clearCache($driverId);
        Cache::forget("envelope_driver:{$driverId}:{$version}");

        return $filePath;
    }

    /**
     * Delete a driver YAML file
     */
    public function delete(string $driverId, string $version): bool
    {
        $filePath = "{$driverId}/v{$version}.yaml";

        if (! $this->disk()->exists($filePath)) {
            return false;
        }

        $this->disk()->delete($filePath);

        // Remove directory if empty
        $remainingFiles = $this->disk()->files($driverId);
        if (empty($remainingFiles)) {
            $this->disk()->deleteDirectory($driverId);
        }

        // Clear cache
        $this->clearCache($driverId);
        Cache::forget("envelope_driver:{$driverId}:{$version}");

        return true;
    }

    /**
     * Check if a driver exists
     */
    public function exists(string $driverId, string $version): bool
    {
        try {
            $this->definition($driverId, $version);

            return true;
        } catch (DriverNotFoundException) {
            return false;
        }
    }

    /**
     * Get count of envelopes using a specific driver
     */
    public function getUsageCount(string $driverId, string $version): int
    {
        return Envelope::where('driver_id', $driverId)
            ->where('driver_version', $version)
            ->count();
    }

    /**
     * Get raw extends array without resolving composition
     */
    public function getRawExtends(string $driverId, string $version): array
    {
        $data = $this->definition($driverId, $version)['data'];

        return $data['extends'] ?? [];
    }

    /**
     * Find all drivers that extend a given driver
     */
    public function getExtendedBy(string $driverId, string $version): array
    {
        $targetRef = "{$driverId}@{$version}";
        $extendedBy = [];

        foreach ($this->list() as $item) {
            $extends = $this->getRawExtends($item['id'], $item['version']);
            foreach ($extends as $parentRef) {
                if ($parentRef === $targetRef || $parentRef === $driverId) {
                    $extendedBy[] = "{$item['id']}@{$item['version']}";
                    break;
                }
            }
        }

        return $extendedBy;
    }

    /**
     * Extract family prefix from driver ID (e.g., "bank.home-loan" from "bank.home-loan.eligible.married")
     */
    public function extractFamily(string $driverId): ?string
    {
        $parts = explode('.', $driverId);
        if (count($parts) >= 2) {
            return $parts[0].'.'.$parts[1];
        }

        return null;
    }

    /**
     * Convert form data to driver YAML structure
     */
    public function toYamlStructure(array $formData): array
    {
        return [
            'driver' => [
                'id' => $formData['id'],
                'version' => $formData['version'],
                'title' => $formData['title'],
                'description' => $formData['description'] ?? null,
                'domain' => $formData['domain'] ?? null,
                'issuer_type' => $formData['issuer_type'] ?? null,
            ],
            'payload' => [
                'schema' => [
                    'id' => $formData['payload_schema_id'] ?? "{$formData['id']}.v{$formData['version']}",
                    'format' => 'json_schema',
                    'inline' => $formData['payload_schema'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass,
                        'required' => [],
                    ],
                ],
                'storage' => [
                    'mode' => 'versioned',
                    'patch_strategy' => 'merge',
                ],
            ],
            'documents' => [
                'registry' => $formData['documents'] ?? [],
            ],
            'checklist' => [
                'template' => $formData['checklist'] ?? [],
            ],
            'signals' => [
                'definitions' => $formData['signals'] ?? [],
            ],
            'gates' => [
                'definitions' => $formData['gates'] ?? [],
            ],
            'audit' => [
                'enabled' => true,
                'capture' => ['payload_patch', 'attachment_upload', 'attachment_review', 'signal_set', 'gate_change'],
            ],
            'manifest' => [
                'enabled' => true,
                'includes' => [
                    'payload_hash' => true,
                    'attachments_hashes' => true,
                    'envelope_fingerprint' => true,
                ],
            ],
        ];
    }
}
