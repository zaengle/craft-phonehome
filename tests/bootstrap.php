<?php

// Define path constants
define('CRAFT_BASE_PATH', dirname(__DIR__, 3));
define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');
define('PLUGIN_PATH', dirname(__DIR__));

// Load Composer's autoloader
require_once PLUGIN_PATH . '/vendor/autoload.php';

// Set environment variables for testing
putenv('PHONE_HOME_TOKEN=test-token');
putenv('PHONE_HOME_CUSTOM_KEYS=TEST_KEY,ANOTHER_KEY');
putenv('TEST_KEY=test_value');
putenv('ANOTHER_KEY=another_value');
