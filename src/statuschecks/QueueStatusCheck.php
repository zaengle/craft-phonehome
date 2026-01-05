<?php

namespace zaengle\phonehome\statuschecks;

use Craft;
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
        return 'Monitors the Craft queue for failed and delayed jobs against configurable thresholds';
    }

    /**
     * @param QueueInterface|null $queue
     * @return StatusCheckResult
     */
    public static function check(?QueueInterface $queue = null): StatusCheckResult
    {
        /** @var QueueInterface $queue */
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
                ],
            ],
        ]);
    }

    protected static function getStatus(QueueInterface $queue, Settings $settings): StatusCheck
    {
        $failedStatus = self::getFailedJobsStatus($queue, $settings);
        $delayedStatus = self::getDelayedJobsStatus($queue, $settings);

        // CRITICAL takes precedence, then WARNING, then OK
        if ($failedStatus === StatusCheck::CRITICAL || $delayedStatus === StatusCheck::CRITICAL) {
            return StatusCheck::CRITICAL;
        }

        if ($failedStatus === StatusCheck::WARNING || $delayedStatus === StatusCheck::WARNING) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }

    protected static function getFailedJobsStatus(QueueInterface $queue, Settings $settings): StatusCheck
    {
        $failed = $queue->getTotalFailed();

        if ($failed >= $settings->getQueueFailedCriticalThreshold()) {
            return StatusCheck::CRITICAL;
        }

        if ($failed >= $settings->getQueueFailedWarningThreshold()) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }

    protected static function getDelayedJobsStatus(QueueInterface $queue, Settings $settings): StatusCheck
    {
        $delayed = $queue->getTotalDelayed();

        if ($delayed >= $settings->getQueueDelayedCriticalThreshold()) {
            return StatusCheck::CRITICAL;
        }

        if ($delayed >= $settings->getQueueDelayedWarningThreshold()) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }
}
