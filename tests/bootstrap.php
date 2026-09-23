<?php

/**
 * PHPUnit bootstrap.
 *
 * These are unit tests, so they deliberately do not boot a Craft application. They exercise the pure
 * data-mapping methods on the Report service, which need only the Craft class itself for its alias
 * helpers. Neither Yii nor Craft is in Composer's classmap, so both are required directly, the same
 * way Craft's own bootstrap does it.
 */

// Set before the autoloader runs: several of Craft's own dependencies emit deprecation notices on
// PHP 8.4 that are unrelated to this plugin and would otherwise drown out the test output.
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../vendor/craftcms/cms/src/Craft.php';
