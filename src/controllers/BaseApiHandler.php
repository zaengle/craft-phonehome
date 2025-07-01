<?php

namespace zaengle\phonehome\controllers;

use Craft;
use craft\base\PluginInterface;
use craft\helpers\App;

abstract class BaseApiHandler
{
    /**
     * Get the main system information
     */
    public function getInfo(): array
    {
        $info = [
            'php_version' => PHP_VERSION,
            'craft_version' => Craft::$app->getVersion(),
            'craft_edition' => Craft::$app->getEditionName(),
            'ip_address' => Craft::$app->getRequest()->getRemoteIP() ?? 'unknown',
            'environment' => App::env('CRAFT_ENVIRONMENT') ?? App::env('ENVIRONMENT') ?? 'unknown',
            'dev_mode' => App::devMode(),
            'timestamp' => date('c'),
            'meta' => [],
        ];

        // Get composer.lock last modified time
        $composerLockPath = Craft::getAlias('@root/composer.lock');

        if (file_exists($composerLockPath)) {
            $info['composer_lock_updated'] = date('c', filemtime($composerLockPath));
        }

        // Add system information
        $info['system'] = $this->getSystemInfo();

        // Add plugins information
        $info['plugins'] = $this->getPluginsInfo();

        // Add modules information
        $info['modules'] = $this->getModulesInfo();

        // Add updates information
        $info['updates'] = $this->getUpdatesInfo();

        // Add any additional custom key-values from environment variables to the meta object
        $customKeys = App::env('PHONE_HOME_CUSTOM_KEYS');
        if ($customKeys) {
            $keys = explode(',', $customKeys);
            foreach ($keys as $key) {
                $key = trim($key);
                $value = App::env($key);
                if ($value !== null) {
                    $info['meta'][$key] = $value;
                }
            }
        }

        return $info;
    }

    protected function getSystemInfo(): array
    {
        $info = [
            'php' => [
                'name' => 'PHP',
                'version' => App::phpVersion(),
            ],
            'os' => [
                'name' => PHP_OS,
                'version' => php_uname('r'),
            ],
            'database' => $this->getDatabaseInfo(),
            'image' => $this->getImageDriverInfo(),
        ];

        // Try to add additional dependency versions if InstalledVersions is available
        if (!class_exists('Composer\InstalledVersions', false)) {
            $path = Craft::$app->getPath()->getVendorPath() . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'InstalledVersions.php';
            if (file_exists($path)) {
                require $path;
            }
        }

        if (class_exists('Composer\InstalledVersions', false)) {
            $this->addVersion($info, 'Yii', 'yiisoft/yii2');
            $this->addVersion($info, 'Twig', 'twig/twig');
            $this->addVersion($info, 'Guzzle', 'guzzlehttp/guzzle');
        }

        return $info;
    }

    protected function getDatabaseInfo(): array
    {
        $db = Craft::$app->getDb();
        return [
            'name' => $db->getDriverLabel(),
            'version' => App::normalizeVersion($db->getSchema()->getServerVersion()),
        ];
    }

    protected function getImageDriverInfo(): array
    {
        $imagesService = Craft::$app->getImages();
        $driverName = $imagesService->getIsGd() ? 'GD' : 'Imagick';

        return [
            'name' => $driverName,
            'version' => $imagesService->getVersion(),
        ];
    }

    protected function addVersion(array &$info, string $label, string $packageName): void
    {
        try {
            if (method_exists('Composer\InstalledVersions', 'getPrettyVersion')) {
                $version = \Composer\InstalledVersions::getPrettyVersion($packageName)
                    ?? \Composer\InstalledVersions::getVersion($packageName);

                if ($version !== null) {
                    $info[strtolower($label)] = [
                        'name' => $label,
                        'version' => $version,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Skip if the package isn't found
        }
    }

    protected function getPluginsInfo(): array
    {
        $plugins = [];
        try {
            $pluginService = Craft::$app->getPlugins();

            if (isset($pluginService)) {
                // First get all enabled plugins
                if (method_exists($pluginService, 'getAllPlugins')) {
                    $allPlugins = $pluginService->getAllPlugins();

                    foreach ($allPlugins as $handle => $plugin) {
                        $plugins[$handle] = [
                            'name' => $plugin->name,
                            'description' => property_exists($plugin, 'description') ? $plugin->description : '',
                            'version' => method_exists($plugin, 'getVersion') ? $plugin->getVersion() : 'unknown',
                            'is_installed' => property_exists($plugin, 'isInstalled') ? $plugin->isInstalled : true,
                            'is_enabled' => true, // These are enabled plugins
                        ];
                    }
                }

                // Then get all disabled plugins
                if (method_exists($pluginService, 'getAllPluginInfo')) {
                    $allPluginInfo = $pluginService->getAllPluginInfo();

                    foreach ($allPluginInfo as $handle => $info) {
                        // Skip if we already have this plugin (it's enabled)
                        if (isset($plugins[$handle])) {
                            continue;
                        }

                        // Add the disabled plugin
                        $plugins[$handle] = [
                            'name' => $info['name'] ?? $handle,
                            'description' => $info['description'] ?? '',
                            'version' => $info['version'] ?? 'unknown',
                            'is_installed' => $info['isInstalled'] ?? false,
                            'is_enabled' => false, // These are disabled plugins
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently handle errors
        }

        return $plugins;
    }

    protected function getModulesInfo(): array
    {
        $modules = [];
        try {
            // 1. Direct approach - Get modules that are already loaded
            if (isset(Craft::$app) && method_exists(Craft::$app, 'getModules')) {
                $loadedModules = Craft::$app->getModules(false); // false = only return loaded modules

                foreach ($loadedModules as $id => $module) {
                    if ($module instanceof PluginInterface) {
                        continue; // Skip plugins
                    }

                    if (is_object($module)) {
                        $modules[$id] = [
                            'class' => get_class($module),
                            'version' => $this->getModuleVersion($module),
                        ];
                    }
                }
            }

            // 2. Check modules registered in app.php
            if (file_exists(Craft::getAlias('@config/app.php'))) {
                $appConfig = require Craft::getAlias('@config/app.php');
                if (isset($appConfig['modules']) && is_array($appConfig['modules'])) {
                    foreach ($appConfig['modules'] as $id => $moduleConfig) {
                        // Skip if already loaded
                        if (isset($modules[$id])) {
                            continue;
                        }

                        $className = null;
                        if (is_string($moduleConfig)) {
                            $className = $moduleConfig;
                        } elseif (is_array($moduleConfig) && isset($moduleConfig['class'])) {
                            $className = $moduleConfig['class'];
                        }

                        if ($className) {
                            $modules[$id] = [
                                'class' => $className,
                                'version' => $this->getClassVersion($className),
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently handle errors
        }

        return $modules;
    }

    protected function getModuleVersion($module): ?string
    {
        // Method 1: Check for schemaVersion property
        if (property_exists($module, 'schemaVersion')) {
            return $module->schemaVersion;
        }

        // Method 2: Check for version property
        if (property_exists($module, 'version')) {
            return $module->version;
        }

        // Method 3: Check for getVersion method
        if (method_exists($module, 'getVersion')) {
            return $module->getVersion();
        }

        // Check for static getVersion method
        $className = get_class($module);

        if (method_exists($className, 'getVersion')) {
            return $className::getVersion();
        }

        return null;
    }

    protected function getClassVersion(string $className): ?string
    {
        if (defined("$className::VERSION")) {
            return $className::VERSION;
        }

        if (defined("$className::SCHEMA_VERSION")) {
            return $className::SCHEMA_VERSION;
        }

        return null;
    }

    abstract protected function getUpdatesInfo(): array;
}
