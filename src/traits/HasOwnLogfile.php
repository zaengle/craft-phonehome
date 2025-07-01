<?php

namespace zaengle\phonehome\traits;

use Craft;
use craft\base\Plugin;
use craft\log\MonologTarget;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

/**
 * Trait HasOwnLogfile
 *
 * Allow a module to log messages to its own log file.
 *
 * @method static Plugin getInstance()
 */
trait HasOwnLogfile
{
    /**
     * Logs an informational message to our custom log target.
     */
    public static function info(string|array $message): void
    {
        self::log($message);
    }

    /**
     * Logs an error message to our custom log target.
     */
    public static function warning(string|array $message): void
    {
        self::log($message, LogLevel::WARNING);
    }

    /**
     * Logs an error message to our custom log target.
     */
    public static function error(string|array $message): void
    {
        self::log($message, LogLevel::ERROR);
    }

    /**
     * Logs a message to our custom log target.
     *
     * @param string|array<mixed> $message
     * @param string $level
     * @see Logger::log()
     * @see registerLogTarget()
     */
    public static function log(string|array $message, string $level = LogLevel::INFO): void
    {
        Craft::getLogger()->log($message, $level, self::getInstance()->getHandle());
    }

    /**
     * Write log messages to a custom log target
     */
    protected function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::getInstance()->getHandle(),
            'categories' => [self::getInstance()->getHandle()],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => true,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }
}
