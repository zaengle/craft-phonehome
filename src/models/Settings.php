<?php

namespace zaengle\phonehome\models;

use Craft;
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
    /**
     * @var string[] The queue threshold attributes, all of which accept either a
     * non-negative integer or an `$ENV_VAR_NAME` reference.
     */
    public const THRESHOLD_ATTRIBUTES = [
        'queueFailedCriticalThreshold',
        'queueFailedWarningThreshold',
        'queueDelayedCriticalThreshold',
        'queueDelayedWarningThreshold',
        'queuePendingCriticalThreshold',
        'queuePendingWarningThreshold',
    ];

    // Public Properties
    // =========================================================================
    public ?string $token = null;
    public int|string $queueFailedCriticalThreshold = 6;
    public int|string $queueFailedWarningThreshold = 3;
    public int|string $queueDelayedCriticalThreshold = 50;
    public int|string $queueDelayedWarningThreshold = 20;
    public int|string $queuePendingCriticalThreshold = 50;
    public int|string $queuePendingWarningThreshold = 20;
    public array $additionalEnvKeys = [];

    public function rules(): array
    {
        return [
            [['token'], 'required'],
            [self::THRESHOLD_ATTRIBUTES, 'validateThreshold'],
        ];
    }

    /**
     * Rejects threshold values that aren't a non-negative integer or an env var
     * reference. Without this a typo like `twenty` or `$MISPELLED_VAR` parses to 0,
     * which silently switches that alert level off with no feedback in the CP.
     *
     * Env var references are only checked for shape, not resolved -- the var may
     * legitimately be defined in some environments and not others.
     */
    public function validateThreshold(string $attribute): void
    {
        $value = $this->$attribute;

        if (is_int($value)) {
            if ($value < 0) {
                $this->addError($attribute, Craft::t('phonehome', 'Threshold must be 0 or greater.'));
            }

            return;
        }

        $value = trim($value);

        // Blank is allowed, and means the same as 0: that level is disabled
        if ($value === '' || str_starts_with($value, '$')) {
            return;
        }

        if (!ctype_digit($value)) {
            $this->addError($attribute, Craft::t('phonehome', 'Threshold must be a non-negative whole number, or an environment variable name beginning with $.'));
        }
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
        return $this->clampWarningThreshold($warning, $critical);
    }

    public function getQueueDelayedCriticalThreshold(): int
    {
        return max(0, $this->parseIntThreshold($this->queueDelayedCriticalThreshold));
    }

    public function getQueueDelayedWarningThreshold(): int
    {
        $warning = max(0, $this->parseIntThreshold($this->queueDelayedWarningThreshold));
        $critical = $this->getQueueDelayedCriticalThreshold();
        return $this->clampWarningThreshold($warning, $critical);
    }

    public function getQueuePendingCriticalThreshold(): int
    {
        return max(0, $this->parseIntThreshold($this->queuePendingCriticalThreshold));
    }

    public function getQueuePendingWarningThreshold(): int
    {
        $warning = max(0, $this->parseIntThreshold($this->queuePendingWarningThreshold));
        $critical = $this->getQueuePendingCriticalThreshold();
        return $this->clampWarningThreshold($warning, $critical);
    }

    /**
     * Clamps a warning threshold to its critical threshold, but only when the critical
     * level is actually enabled. A critical threshold of 0 means "don't escalate to
     * CRITICAL" -- it must not silently switch the warning level off as well.
     */
    private function clampWarningThreshold(int $warning, int $critical): int
    {
        return $critical > 0 ? min($warning, $critical) : $warning;
    }

    private function parseIntThreshold(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $parsed = App::parseEnv($value);

        // App::parseEnv() returns null for an unset env var and can return a bool for
        // one set to "true"/"false"; neither is a threshold, so treat them as disabled
        // rather than casting them to 0 or 1.
        return is_numeric($parsed) ? (int) $parsed : 0;
    }
}
