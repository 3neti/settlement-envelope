<?php

namespace LBHurtado\SettlementEnvelope\Services;

use LBHurtado\SettlementEnvelope\Contracts\WorkflowAccessPolicy;
use LBHurtado\SettlementEnvelope\Contracts\WorkflowCatalog;
use LBHurtado\SettlementEnvelope\Data\WorkflowContext;
use LBHurtado\SettlementEnvelope\Data\WorkflowDescriptor;
use LBHurtado\SettlementEnvelope\Exceptions\DriverNotFoundException;

class YamlWorkflowCatalog implements WorkflowCatalog
{
    public function __construct(
        private DriverService $drivers,
        private WorkflowAccessPolicy $access,
        private WorkflowConnectionReadiness $connections,
    ) {}

    public function available(WorkflowContext $context): array
    {
        $descriptors = [];
        foreach ($this->drivers->workflowReferences() as $reference) {
            if (! $this->access->allows($context, $reference['id'], $reference['version'])) {
                continue;
            }
            $driver = $this->drivers->loadExact($reference['id'], $reference['version'], requireWorkflow: true);
            if ($driver->workflow === null) {
                continue;
            }
            $descriptors[$driver->getDriverKey()] = $this->resolve($driver->id, $driver->version, $context);
        }
        ksort($descriptors);

        return array_values($descriptors);
    }

    public function resolve(string $id, string $version, WorkflowContext $context): WorkflowDescriptor
    {
        if (! $this->access->allows($context, $id, $version)) {
            throw new DriverNotFoundException('Workflow is unavailable.');
        }
        $driver = $this->drivers->loadExact($id, $version, requireWorkflow: true);
        if ($driver->workflow === null) {
            throw new DriverNotFoundException('Workflow is unavailable.');
        }

        return new WorkflowDescriptor(
            $driver->id,
            $driver->version,
            $driver->title,
            $driver->workflow,
            $this->connections->check($driver->workflow->connection),
            $driver->documents,
            $driver->checklist,
            $driver->gates->toCollection()->map(fn ($gate): string => $gate->key)->values()->all(),
        );
    }
}
