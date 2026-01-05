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
        return is_int($this->queueFailedCriticalThreshold)
            ? $this->queueFailedCriticalThreshold
            : (int) App::parseEnv($this->queueFailedCriticalThreshold);
    }
    public function getQueueFailedWarningThreshold(): int
    {
        return is_int($this->queueFailedWarningThreshold)
            ? $this->queueFailedWarningThreshold
            : (int) App::parseEnv($this->queueFailedWarningThreshold);
    }

    public function getQueueDelayedCriticalThreshold(): int
    {
        return is_int($this->queueDelayedCriticalThreshold)
            ? $this->queueDelayedCriticalThreshold
            : (int) App::parseEnv($this->queueDelayedCriticalThreshold);
    }

    public function getQueueDelayedWarningThreshold(): int
    {
        return is_int($this->queueDelayedWarningThreshold)
            ? $this->queueDelayedWarningThreshold
            : (int) App::parseEnv($this->queueDelayedWarningThreshold);
    }
}
