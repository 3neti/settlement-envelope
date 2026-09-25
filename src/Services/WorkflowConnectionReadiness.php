<?php

namespace LBHurtado\SettlementEnvelope\Services;

use Illuminate\Contracts\Config\Repository;
use LBHurtado\SettlementEnvelope\Data\WorkflowReadinessData;

class WorkflowConnectionReadiness
{
    public function __construct(private Repository $config) {}

    public function check(?string $name): WorkflowReadinessData
    {
        if ($name === null) {
            return new WorkflowReadinessData(true);
        }
        $connections = $this->config->get('settlement-envelope.connections', []);
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;
        if (! is_array($connection)) {
            return new WorkflowReadinessData(false, 'connection_missing');
        }
        $url = $connection['base_url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        $auth = $connection['auth'] ?? null;
        $valid = ($connection['driver'] ?? null) === 'http'
            && is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && is_int($connection['connect_timeout'] ?? null) && $connection['connect_timeout'] > 0 && $connection['connect_timeout'] <= 120
            && is_int($connection['timeout'] ?? null) && $connection['timeout'] >= $connection['connect_timeout'] && $connection['timeout'] <= 120
            && is_array($auth) && in_array($auth['type'] ?? null, ['none', 'bearer'], true);
        if ($valid && $auth['type'] === 'bearer') {
            $valid = is_string($auth['token'] ?? null) && trim($auth['token']) !== '' && ! preg_match('/[\r\n]/', $auth['token']);
        }

        return new WorkflowReadinessData($valid, $valid ? null : 'connection_invalid');
    }
}
