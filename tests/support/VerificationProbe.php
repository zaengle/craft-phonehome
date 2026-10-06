<?php

namespace zaengle\phonehome\tests\support;

use zaengle\phonehome\services\Verification;

/**
 * Stands in for the two database lookups behind automatic page selection, so the selection logic
 * can be exercised without a booted Craft application.
 */
class VerificationProbe extends Verification
{
    /** @var array<string, string> render target => representative live uri */
    public array $representatives = [];

    /** @var array<string, string> uri => render target, for the explicit pages */
    public array $explicitTemplates = [];

    /** @var list<string> render targets with nothing live behind them */
    public array $dormant = [];

    /** @var array<string, string> category template => representative live uri */
    public array $categoryRepresentatives = [];

    /** Set to simulate the selection query failing. */
    public bool $failSelection = false;

    /** @var string[] */
    public array $loggedErrors = [];

    /**
     * @param array<mixed> $explicit
     * @param array<mixed> $defaultAssert
     * @param array<mixed> $masks
     * @return array<mixed>
     */
    public function autoCover(array $explicit, array $defaultAssert = ['visible' => 'h1'], array $masks = []): array
    {
        return $this->withAutoCoverage($explicit, $defaultAssert, $masks);
    }

    public function manifestForConfig(array $raw): array
    {
        return $this->configuredManifest($raw);
    }

    /** @return list<string> */
    public function reportedWarnings(): array
    {
        return $this->warnings;
    }

    protected function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
    }

    protected function representativeUris(): array
    {
        if ($this->failSelection) {
            throw new \RuntimeException('selection query failed');
        }

        return $this->representatives;
    }

    protected function templatesForUris(string $settingsTable, string $elementTable, string $foreignKey, array $uris): array
    {
        return [];
    }

    protected function entryTargetsForUris(array $uris): array
    {
        return array_intersect_key($this->explicitTemplates, array_flip($uris));
    }

    protected function representativeCategoryUris(): array
    {
        return $this->categoryRepresentatives;
    }

    protected function dormantTargets(): array
    {
        return $this->dormant;
    }
}
