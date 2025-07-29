<?php

namespace zaengle\phonehome\services;

use Composer\InstalledVersions;
use Craft;
use craft\db\Connection;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\helpers\Db;
use craft\models\UpdateRelease;
use OutOfBoundsException;
use RequirementsChecker;
use yii\base\Component;
use zaengle\phonehome\PhoneHome;

/**
 * Report service
 *
 * @property-read array $imageDriverInfo
 * @property-read array $modulesInfo
 * @property-read array $pluginsInfo
 * @property-read array $databaseInfo
 * @property-read array $metaInfo
 * @property-read array $systemInfo
 * @property-read array $updatesInfo
 * @property-read array $info
 */
class Report extends Component
{
    public function getInfo(bool $expandPhpInfo = false): array
    {
        return [
            'api_version' => PhoneHome::getApiVersion(),
            'timestamp' => date('c'),
            'php_version' => App::phpVersion(),
            'craft_version' => Craft::$app->getVersion(),
            'craft_edition' => $this->getCraftEdition(),
            'ip_address' => Craft::$app->getRequest()->getRemoteIP() ?? 'unknown',
            'environment' => App::env('CRAFT_ENVIRONMENT') ?? 'unknown',
            'dev_mode' => App::devMode(),
            'composer_lock_updated' => date('c', filemtime(Craft::$app->getComposer()->getLockPath())),
            'system' => $this->getSystemInfo($expandPhpInfo),
            'plugins' => $this->getPluginsInfo(),
            'modules' => $this->getModulesInfo(),
            'updates' => $this->getUpdatesInfo(),
            'meta' => $this->getMetaInfo(),
        ];
    }

    protected function getMetaInfo(): array
    {
        $meta = [];

        foreach (PhoneHome::$plugin->getSettings()->additionalEnvKeys as $key) {
            $key = trim($key);
            $value = App::env($key);
            if ($value !== null) {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    protected function getSystemInfo(bool $expandPhpInfo = false): array
    {
        $updatesService = Craft::$app->getUpdates();
        $info = [
            'php' => [
                'name' => 'PHP',
                'version' => App::phpVersion(),
                'info' => $expandPhpInfo ? $this->phpInfo() : null,
            ],
            'os' => [
                'name' => PHP_OS,
                'version' => php_uname('r'),
            ],
            'database' => $this->getDatabaseInfo(),
            'image' => $this->getImageDriverInfo(),
            'craft' => [
                'version' => Craft::$app->getVersion(),
                'edition' => $this->getCraftEdition(),
                'update_status' => [
                    'total_available_updates' => $updatesService->getTotalAvailableUpdates(true),
                    'pending_migrations' => $updatesService->getPendingMigrationHandles(),
                    'was_craft_breakpoint_skipped' => $updatesService->getWasCraftBreakpointSkipped(),
                    'is_update_pending' => $updatesService->getIsUpdatePending(),
                    'is_craft_update_pending' => $updatesService->getIsCraftUpdatePending(),
                    'is_plugin_update_pending' => $updatesService->getIsPluginUpdatePending(),
                    'is_critical_update_available' => $updatesService->getIsCriticalUpdateAvailable(true),
                ],
                'requirements' => $this->requirementsStatus(),
                'aliases' => $this->getAliases(),
            ],
        ];

        // Try to add additional dependency versions if InstalledVersions is available
        if (!class_exists(InstalledVersions::class, false)) {
            $path = Craft::$app->getPath()->getVendorPath() . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'InstalledVersions.php';
            if (file_exists($path)) {
                require $path;
            }
        }

        if (class_exists(InstalledVersions::class, false)) {
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

    protected function getAliases(): array
    {
        $aliases = [];
        foreach (Craft::$aliases as $alias => $value) {
            if (is_array($value)) {
                foreach ($value as $a => $v) {
                    $aliases[$a] = $v;
                }
            } else {
                $aliases[$alias] = $value;
            }
        }
        ksort($aliases);

        return $aliases;
    }

    protected function addVersion(array &$info, string $label, string $packageName): void
    {
        try {
            $version = InstalledVersions::getPrettyVersion($packageName) ?? InstalledVersions::getVersion($packageName);
        } catch (OutOfBoundsException) {
            return;
        }

        if ($version !== null) {
            $info[$label] = $version;
        }
    }

    protected function getPluginsInfo(): array
    {
        return collect(Craft::$app->getPlugins()->getAllPluginInfo())
            ->mapWithKeys(function($info, $handle) {
                return [
                    $handle => [
                        'name' => $info['name'] ?? $handle,
                        'handle' => $handle,
                        'description' => $info['description'],
                        'version' => $info['version'] ?? 'unknown',
                        'is_installed' => $info['isInstalled'],
                        'is_enabled' => $info['isEnabled'],
                        'is_upgrade_available' => $info['upgradeAvailable'],
                    ],
                ];
            })
            ->toArray();
    }

    protected function getModulesInfo(): array
    {
        $nonPluginModuleHandles = array_diff(
            array_keys(Craft::$app->modules),
            array_keys(Craft::$app->plugins->allPluginInfo)
        );

        $modules = [];

        foreach (Craft::$app->modules as $handle => $module) {
            if (in_array($handle, $nonPluginModuleHandles, true)) {
                $modules[$handle] = [
                    'class' => get_class($module),
                ];
            }
        }

        return $modules;
    }

    public function getUpdatesInfo(): array
    {
        $updates = [];

        try {
            PhoneHome::info('Starting Craft update check...');
            $updatesService = Craft::$app->getUpdates();
            $updatesModel = $updatesService->getUpdates(true);
            PhoneHome::info('Got updates model: ' . json_encode($updatesModel));

            // Extract CMS updates
            if ($updatesModel->cms) {
                foreach ($updatesModel->cms->releases as $release) {
                    /* @var UpdateRelease $release */
                    $updates[] = [
                        'name' => 'Craft CMS',
                        'version' => $release->version,
                        'package' => 'craftcms/cms',
                        'critical' => $release->critical,
                        'release_date' => date('c', $release->date),
//                        'notes' => $release->notes,
                    ];
                }

                PhoneHome::info('Added ' . count($updatesModel->cms->releases) . ' Craft CMS updates');
            }

            // Extract plugin updates
            foreach ($updatesModel->plugins as $pluginHandle => $pluginData) {
                if (!empty($pluginData->releases)) {
                    foreach ($pluginData->releases as $release) {
                        $updates[] = [
                            'name' => $pluginHandle,
                            'abandoned' => $pluginData->abandoned,
                            'status' => $pluginData->status,
                            'version' => $release->version,
                            'package' => $pluginData->packageName,
                            'critical' => $release->critical,
                            'release_date' => date('c', $release->date),
//                            'notes' => $release->notes,
                        ];
                    }

                    PhoneHome::info("Added " . count($pluginData->releases) . " updates for plugin $pluginHandle");
                }
            }
        } catch (\Throwable $e) {
            PhoneHome::warning('Error extracting detailed update info: ' . $e->getMessage());
        }

        PhoneHome::info('Total updates found: ' . count($updates));

        return $updates;
    }

    public function requirementsStatus(): array
    {
        $reqCheck = new RequirementsChecker();
        $dbConfig = Craft::$app->getConfig()->getDb();
        $reqCheck->dsn = $dbConfig->dsn;
        $reqCheck->dbDriver = $dbConfig->dsn ? Db::parseDsn($dbConfig->dsn, 'driver') : Connection::DRIVER_MYSQL;
        $reqCheck->dbUser = $dbConfig->user;
        $reqCheck->dbPassword = $dbConfig->password;
        $reqCheck->checkCraft();

        return $reqCheck->getResult()['requirements'];
    }

    public function phpInfo(): array
    {
        // Remove any arrays from $_ENV and $_SERVER to get around an "Array to string conversion" error
        $envVals = [];
        $serverVals = [];

        foreach ($_ENV as $key => $value) {
            if (is_array($value)) {
                $envVals[$key] = $value;
                $_ENV[$key] = 'Array';
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_array($value)) {
                $serverVals[$key] = $value;
                $_SERVER[$key] = 'Array';
            }
        }

        ob_start();
        phpinfo(INFO_ALL);
        $phpInfoStr = ob_get_clean();

        // Put the original $_ENV and $_SERVER values back
        foreach ($envVals as $key => $value) {
            $_ENV[$key] = $value;
        }
        foreach ($serverVals as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $replacePairs = [
            '#^.*<body>(.*)</body>.*$#ms' => '$1',
            '#<h2>PHP License</h2>.*$#ms' => '',
            '#<h1>Configuration</h1>#' => '',
            "#\r?\n#" => '',
            '#</(h1|h2|h3|tr)>#' => '</$1>' . "\n",
            '# +<#' => '<',
            "#[ \t]+#" => ' ',
            '#&nbsp;#' => ' ',
            '#  +#' => ' ',
            '# class=".*?"#' => '',
            '%&#039;%' => ' ',
            '#<tr>(?:.*?)"src="(?:.*?)=(.*?)" alt="PHP Logo" /></a><h1>PHP Version (.*?)</h1>(?:\n+?)</td></tr>#' => '<h2>PHP Configuration</h2>' . "\n" . '<tr><td>PHP Version</td><td>$2</td></tr>' . "\n" . '<tr><td>PHP Egg</td><td>$1</td></tr>',
            '#<h1><a href="(?:.*?)\?=(.*?)">PHP Credits</a></h1>#' => '<tr><td>PHP Credits Egg</td><td>$1</td></tr>',
            '#<tr>(?:.*?)" src="(?:.*?)=(.*?)"(?:.*?)Zend Engine (.*?),(?:.*?)</tr>#' => '<tr><td>Zend Engine</td><td>$2</td></tr>' . "\n" . '<tr><td>Zend Egg</td><td>$1</td></tr>',
            '# +#' => ' ',
            '#<tr>#' => '%S%',
            '#</tr>#' => '%E%',
        ];

        $phpInfoStr = preg_replace(array_keys($replacePairs), array_values($replacePairs), $phpInfoStr);

        $sections = explode('<h2>', strip_tags($phpInfoStr, '<h2><th><td>'));
        unset($sections[0]);

        $phpInfo = [];
        $security = Craft::$app->getSecurity();

        foreach ($sections as $section) {
            $heading = substr($section, 0, strpos($section, '</h2>'));

            if (preg_match_all('#%S%(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?%E%#', $section, $matches, PREG_SET_ORDER) !== 0) {
                foreach ($matches as $row) {
                    if (!isset($row[2])) {
                        continue;
                    }

                    $value = $row[2];
                    $name = $row[1];

                    $phpInfo[$heading][$name] = $security->redactIfSensitive($name, $value);
                }
            }
        }

        return $phpInfo;
    }

    private function getCraftEdition(): string
    {
        if (class_exists(CmsEdition::class, false) && Craft::$app->edition instanceof CmsEdition) {
            return Craft::$app->edition->name;
        }
        return Craft::$app->getEditionName();
    }
}
