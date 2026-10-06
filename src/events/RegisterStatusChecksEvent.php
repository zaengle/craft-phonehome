<?php

namespace zaengle\phonehome\events;

use yii\base\Event;

/**
 * RegisterStatusChecksEvent class
 *
 * This event is triggered when the plugin collects status checks,
 * allowing other plugins or modules to register their own custom status checks.
 *
 * @property array $checks Array of status check class names that implement StatusCheckInterface
 */
class RegisterStatusChecksEvent extends Event
{
    /**
     * @var array Array of status check class names (fully qualified)
     * Each class must implement StatusCheckInterface
     */
    public array $checks = [];
}
