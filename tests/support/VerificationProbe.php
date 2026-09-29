<?php

namespace zaengle\phonehome\tests\support;

use zaengle\phonehome\services\Verification;

/**
 * Stands in for the two database lookups behind automatic page selection, so the selection logic
 * can be exercised without a booted Craft application.
 */
class VerificationProbe extends Verification
{
    /** @var array<string, string> template => representative live uri */
    public array $representatives = [];

    /** @var array<string, string> uri => template, for the explicit pages */
    public array $explicitTemplates = [];

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
        return array_intersect_key($this->explicitTemplates, array_flip($uris));
    }
}
