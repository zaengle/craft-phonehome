<?php

namespace zaengle\phonehome\statuschecks;

use Craft;
use craft\queue\Queue;
use craft\queue\QueueInterface;
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
     * @param QueueInterface|null $queue
     * @return StatusCheckResult
     */
    public static function check(?QueueInterface $queue = null): StatusCheckResult
    {
        $queue = $queue ?? Craft::$app->getQueue();

        // getTotalFailed(), getTotalDelayed(), getTotalWaiting() and getTotalReserved()
        // are declared on craft\queue\Queue, not on QueueInterface, so a site that has
        // swapped in another yii\queue driver has no counts for us to score. Craft
        // guards the same way before offering its own Queue Manager utility.
        if (!$queue instanceof Queue) {
            return self::getUnsupportedDriverResult($queue);
        }

        /** @var Settings $settings */
        $settings = PhoneHome::getInstance()->getSettings();

        // Read each count once. getTotal*() re-queries on every call, so reading them
        // twice would let the reported status disagree with the reported counts.
        $failed = $queue->getTotalFailed();
        $delayed = $queue->getTotalDelayed();
        $waiting = $queue->getTotalWaiting();
        $reserved = $queue->getTotalReserved();

        return new StatusCheckResult([
            'name' => self::getName(),
            'status' => self::getStatus($failed, $delayed, $waiting, $settings),
            'description' => self::getDescription(),
            'meta' => [
                'delayed' => $delayed,
                'waiting' => $waiting,
                'failed' => $failed,
                'reserved' => $reserved,
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

    protected static function getStatus(int $failed, int $delayed, int $waiting, Settings $settings): StatusCheck
    {
        $statuses = [
            self::getFailedJobsStatus($failed, $settings),
            self::getDelayedJobsStatus($delayed, $settings),
            self::getPendingJobsStatus($waiting, $settings),
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

    protected static function getFailedJobsStatus(int $failed, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $failed,
            $settings->getQueueFailedWarningThreshold(),
            $settings->getQueueFailedCriticalThreshold(),
        );
    }

    protected static function getDelayedJobsStatus(int $delayed, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $delayed,
            $settings->getQueueDelayedWarningThreshold(),
            $settings->getQueueDelayedCriticalThreshold(),
        );
    }

    protected static function getPendingJobsStatus(int $waiting, Settings $settings): StatusCheck
    {
        return self::compareToThresholds(
            $waiting,
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

    protected static function getUnsupportedDriverResult(object $queue): StatusCheckResult
    {
        return new StatusCheckResult([
            'name' => self::getName(),
            'status' => StatusCheck::OK,
            'description' => self::getDescription(),
            'meta' => [
                'error' => sprintf(
                    'Job counts are unavailable for this queue driver (%s); only %s reports them.',
                    $queue::class,
                    Queue::class,
                ),
            ],
        ]);
    }
}
