<?php

namespace LBHurtado\SettlementEnvelope\Services;

use InvalidArgumentException;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowCatalog;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowIntegrationAdapter;
use LBHurtado\SettlementEnvelope\Data\WorkflowContext;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;

/**
 * Resolves explicitly supplied adapters, never submits or authorizes execution.
 * Hosts remain responsible for submission validation, evidence gates, payment,
 * review, idempotency, and execution authorization before calling submit().
 */
class WorkflowIntegrationRegistry
{
    /** @var array<string, array<string, WorkflowIntegrationAdapter>> */
    private array $adapters = [];

    /** @param iterable<WorkflowIntegrationAdapter> $adapters */
    public function __construct(private WorkflowCatalog $catalog, iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            if (! $adapter instanceof WorkflowIntegrationAdapter) {
                throw new InvalidArgumentException('Invalid workflow integration adapter.');
            }
            $id = $adapter->workflowId();
            $version = $adapter->workflowVersion();
            if (trim($id) === '' || trim($version) === '' || isset($this->adapters[$id][$version])) {
                throw new InvalidArgumentException('Invalid or duplicate workflow integration identity.');
            }
            $this->adapters[$id][$version] = $adapter;
        }
    }

    public function resolve(string $id, string $version, WorkflowContext $context): WorkflowIntegrationAdapter
    {
        $descriptor = $this->catalog->resolve($id, $version, $context);
        $adapter = $this->adapters[$id][$version] ?? null;
        if ($descriptor->id !== $id || $descriptor->version !== $version
            || ! $descriptor->readiness->configured || $descriptor->readiness->reason !== null
            || $adapter === null || $adapter->workflowId() !== $id || $adapter->workflowVersion() !== $version) {
            throw new DriverNotFoundException('Workflow integration is unavailable.');
        }

        return $adapter;
    }
}
