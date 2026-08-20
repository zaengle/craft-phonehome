<?php

namespace zaengle\phonehome\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * PhoneHome Plugin Settings Model
 *
 * @since     1.0.0
 * @property-read  int $queueFailedCriticalThreshold
 * @property-read int $queueFailedWarningThreshold
 * @property-read int $queueDelayedCriticalThreshold
 * @property-read int $queueDelayedWarningThreshold
 * @property-read int $queuePendingCriticalThreshold
 * @property-read int $queuePendingWarningThreshold
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================
    public ?string $token = null;
    public int|string $queueFailedCriticalThreshold = 6;
    public int|string $queueFailedWarningThreshold = 3;
    public int|string $queueDelayedCriticalThreshold = 50;
    public int|string $queueDelayedWarningThreshold = 20;
    public int|string $queuePendingCriticalThreshold = 250;
    public int|string $queuePendingWarningThreshold = 100;
    public array $additionalEnvKeys = [];

    public function rules(): array
    {
        return [
            [['token'], 'required'],
        ];
    }

    public function getToken(): ?string
    {
        return App::parseEnv($this->token);
    }

    public function getQueueFailedCriticalThreshold(): int
    {
        return max(0, $this->parseIntThreshold($this->queueFailedCriticalThreshold));
    }

    public function getQueueFailedWarningThreshold(): int
    {
        $warning = max(0, $this->parseIntThreshold($this->queueFailedWarningThreshold));
        $critical = $this->getQueueFailedCriticalThreshold();
        return min($warning, $critical);
    }

    public function getQueueDelayedCriticalThreshold(): int
    {
        return max(0, $this->parseIntThreshold($this->queueDelayedCriticalThreshold));
    }

    public function getQueueDelayedWarningThreshold(): int
    {
        $warning = max(0, $this->parseIntThreshold($this->queueDelayedWarningThreshold));
        $critical = $this->getQueueDelayedCriticalThreshold();
        return min($warning, $critical);
    }

    public function getQueuePendingCriticalThreshold(): int
    {
        return max(0, $this->parseIntThreshold($this->queuePendingCriticalThreshold));
    }

    public function getQueuePendingWarningThreshold(): int
    {
        $warning = max(0, $this->parseIntThreshold($this->queuePendingWarningThreshold));
        $critical = $this->getQueuePendingCriticalThreshold();
        return min($warning, $critical);
    }

    private function parseIntThreshold(int|string $value): int
    {
        return is_int($value) ? $value : (int) App::parseEnv($value);
    }
}
