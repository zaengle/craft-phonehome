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
        return 'Monitors the Craft queue for failed, delayed, and reserved jobs';
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
                    'failedWarning' => $settings->getQueueFailedWarningThreshold(),
                    'failedCritical' => $settings->getQueueFailedCriticalThreshold(),
                ],
            ],
        ]);
    }

    protected static function getStatus(QueueInterface $queue, Settings $settings): StatusCheck
    {
        if ($queue->getTotalFailed() >= $settings->getQueueFailedCriticalThreshold()) {
            return StatusCheck::CRITICAL;
        }

        if ($queue->getTotalFailed() >= $settings->getQueueFailedWarningThreshold()) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }
}
