<?php

namespace zaengle\phonehome\tests\support;

use zaengle\phonehome\services\Report;

/**
 * Gives tests access to the protected collection methods on the Report service, and captures the
 * errors it logs so that skipped and unreadable inputs can be asserted on without a Craft app.
 */
class ReportProbe extends Report
{
    /** @var string[] */
    public array $loggedErrors = [];

    /** Stands in for the npmPath plugin setting, which needs a booted Craft application to read. */
    public ?string $npmPath = null;

    /**
     * @param array<mixed> $declared
     * @param array<mixed>|null $lock
     * @return array<string, array{constraint: string|null, version: string|null}>
     */
    public function mapDependencies(array $declared, ?array $lock): array
    {
        return $this->mapNpmDependencies($declared, $lock);
    }

    public function stripCredentials(?string $value): ?string
    {
        return $this->stripUrlCredentials($value);
    }

    /**
     * @return array<string, mixed>
     */
    public function npmInfo(): array
    {
        return $this->getNpmInfo();
    }

    protected function getConfiguredNpmPath(): ?string
    {
        return $this->npmPath;
    }

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }
}
