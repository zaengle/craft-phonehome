<?php

namespace zaengle\phonehome\statuschecks;

use Craft;
use craft\queue\Queue;
use zaengle\phonehome\enums\StatusCheck;
use zaengle\phonehome\models\Settings;
use zaengle\phonehome\models\StatusCheckResult;
use zaengle\phonehome\PhoneHome;

class QueueStatusCheck implements StatusCheckInterface
{
    public static function getName(): string
    {
        return 'Queue Status';
    }

    public static function getDescription(): string
    {
        return 'Monitors the Craft queue for failed, delayed, and pending jobs against configurable thresholds';
    }

    /**
     * @param Queue|null $queue
     * @return StatusCheckResult
     */
    public static function check(?Queue $queue = null): StatusCheckResult
    {
        /** @var Queue $queue */
        $queue = $queue ?? Craft::$app->getQueue();

        /** @var Settings $settings */
        $settings = PhoneHome::getInstance()->getSettings();

        return new StatusCheckResult([
            'name' => self::getName(),
            'status' => self::getStatus($queue, $settings),
            'description' => self::getDescription(),
            'meta' => [
                'delayed' => $queue->getTotalDelayed(),
                'waiting' => $queue->getTotalWaiting(),
                'failed' => $queue->getTotalFailed(),
                'reserved' => $queue->getTotalReserved(),
                'thresholds' => [
                    'failed' => [
                        'warning' => $settings->getQueueFailedWarningThreshold(),
                        'critical' => $settings->getQueueFailedCriticalThreshold(),
                    ],
                    'delayed' => [
                        'warning' => $settings->getQueueDelayedWarningThreshold(),
                        'critical' => $settings->getQueueDelayedCriticalThreshold(),
                    ],
                    // The `pending` thresholds are compared against `meta.waiting`,
                    // which is what Craft's CP labels "Pending"
                    'pending' => [
                        'warning' => $settings->getQueuePendingWarningThreshold(),
                        'critical' => $settings->getQueuePendingCriticalThreshold(),
                    ],
                ],
            ],
        ]);
    }

    protected static function getStatus(Queue $queue, Settings $settings): StatusCheck
    {
        $statuses = [
            self::getFailedJobsStatus($queue, $settings),
            self::getDelayedJobsStatus($queue, $settings),
            self::getPendingJobsStatus($queue, $settings),
        ];

        // CRITICAL takes precedence, then WARNING, then OK
        if (in_array(StatusCheck::CRITICAL, $statuses, true)) {
            return StatusCheck::CRITICAL;
        }

        if (in_array(StatusCheck::WARNING, $statuses, true)) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }

    protected static function getFailedJobsStatus(Queue $queue, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $queue->getTotalFailed(),
            $settings->getQueueFailedWarningThreshold(),
            $settings->getQueueFailedCriticalThreshold(),
        );
    }

    protected static function getDelayedJobsStatus(Queue $queue, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $queue->getTotalDelayed(),
            $settings->getQueueDelayedWarningThreshold(),
            $settings->getQueueDelayedCriticalThreshold(),
        );
    }

    protected static function getPendingJobsStatus(Queue $queue, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $queue->getTotalWaiting(),
            $settings->getQueuePendingWarningThreshold(),
            $settings->getQueuePendingCriticalThreshold(),
        );
    }

    /**
     * A threshold of 0 disables that level, so an unset env var can't pin the check
     * to WARNING or CRITICAL permanently.
     */
    protected static function compareToThresholds(int $count, int $warning, int $critical): StatusCheck
    {
        if ($critical > 0 && $count >= $critical) {
            return StatusCheck::CRITICAL;
        }

        if ($warning > 0 && $count >= $warning) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }
}
