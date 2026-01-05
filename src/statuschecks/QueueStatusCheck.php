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
    public const HANDLE = 'queue';

    public static function getHandle(): string
    {
        return self::HANDLE;
    }

    /**
     * @param QueueInterface|null $queue
     * @return StatusCheckResult
     */
    public static function check(?QueueInterface $queue = null): StatusCheckResult
    {
        /** @var QueueInterface $queue */
        $queue = $queue ?? Craft::$app->getQueue();

        return new StatusCheckResult([
            'handle' => self::getHandle(),
            'status' => self::getStatus($queue),
            'meta' => [
                'delayed' => $queue->getTotalDelayed(),
                'waiting' => $queue->getTotalWaiting(),
                'failed' => $queue->getTotalFailed(),
                'reserved' => $queue->getTotalReserved(),
            ],
        ]);
    }

    protected static function getStatus(QueueInterface $queue): StatusCheck
    {
        /** @var Settings $settings */
        $settings = PhoneHome::getInstance()->getSettings();

        if ($queue->getTotalFailed() >= $settings->getQueueFailedCriticalThreshold()) {
            return StatusCheck::CRITICAL;
        }

        if ($queue->getTotalFailed() >= $settings->getQueueFailedWarningThreshold()) {
            return StatusCheck::WARNING;
        }

        return StatusCheck::OK;
    }
}
