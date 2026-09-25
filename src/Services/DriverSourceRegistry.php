<?php

namespace LBHurtado\SettlementEnvelope\Services;

use LBHurtado\SettlementEnvelope\Exceptions\InvalidDriverException;

/** Application-local, declarative package resource registrations. */
class DriverSourceRegistry
{
    /** @var array<string, string> */
    private array $roots = [];

    public function register(string $name, string $root): void
    {
        $resolved = realpath($root);
        if ($name === '' || $name === 'host' || $resolved === false || ! is_dir($resolved) || ! is_readable($resolved)) {
            throw new InvalidDriverException('Invalid driver source registration.');
        }
        if (isset($this->roots[$name]) && $this->roots[$name] !== $resolved) {
            throw new InvalidDriverException('Driver source name is already registered.');
        }
        $this->roots[$name] = $resolved;
        ksort($this->roots);
    }

    /** @return array<string, string> */
    public function roots(): array
    {
        return $this->roots;
    }

    public function read(string $source, string $path): string
    {
        $root = $this->roots[$source] ?? null;
        $resolved = $root === null ? false : realpath($root.'/'.$path);
        if ($resolved === false || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_file($resolved) || ! is_readable($resolved) || filesize($resolved) > 1048576) {
            throw new InvalidDriverException('Invalid package driver resource.');
        }

        return file_get_contents($resolved);
    }
}
