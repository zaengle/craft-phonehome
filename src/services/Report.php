<?php

namespace zaengle\phonehome\services;

use Composer\InstalledVersions;
use Craft;
use craft\db\Connection;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\models\UpdateRelease;
use OutOfBoundsException;
use RequirementsChecker;
use yii\base\Component;
use zaengle\phonehome\enums\NpmStatus;
use zaengle\phonehome\events\RegisterStatusChecksEvent;
use zaengle\phonehome\PhoneHome;
use zaengle\phonehome\statuschecks\StatusCheckInterface;

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
 * @property-read array $npmInfo
 * @property-read array $info
 */
class Report extends Component
{
    /**
     * @event RegisterStatusChecksEvent The event that is triggered when registering status checks
     */
    public const EVENT_REGISTER_STATUS_CHECKS = 'registerStatusChecks';

    public function getInfo(bool $expandPhpInfo = false): array
    {
        return [
            'api_version' => PhoneHome::getApiVersion(),
            'build_id' => Craft::$app->config->general->buildId,
            'timestamp' => date('c'),
            'php_version' => App::phpVersion(),
            'craft_version' => Craft::$app->getVersion(),
            'craft_edition' => $this->getCraftEdition(),
            'ip_address' => Craft::$app->getRequest()->getRemoteIP() ?? 'unknown',
            'environment' => App::env('CRAFT_ENVIRONMENT') ?? 'unknown',
            'dev_mode' => App::devMode(),
            'composer_lock_updated' => $this->fileUpdatedAt(Craft::$app->getComposer()->getLockPath()),
            'npm' => $this->getNpmInfo(),
            'system' => $this->getSystemInfo($expandPhpInfo),
            'plugins' => $this->getPluginsInfo(),
            'modules' => $this->getModulesInfo(),
            'updates' => $this->getUpdatesInfo(),
            'meta' => $this->getMetaInfo(),
            'status_checks' => $this->getStatusChecks(),
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

    /**
     * Reads declared npm packages from package.json and their resolved versions from package-lock.json.
     *
     * Always returns an object so that a consumer can tell a site with no npm dependencies apart from
     * a site whose dependencies could not be read. The `status` field says which of those it is.
     *
     * @return array{status: string, package_manager: string|null, lock_updated: string|null, dependencies: object, dev_dependencies: object}
     */
    protected function getNpmInfo(): array
    {
        // Reading the manifest and reading the lockfile are caught separately, so that a failure after
        // the manifest was read is not reported as a manifest problem.
        try {
            $root = Craft::getAlias('@root');
            $packagePath = $root . DIRECTORY_SEPARATOR . 'package.json';

            if (!is_file($packagePath)) {
                return $this->npmInfoResult(NpmStatus::NO_MANIFEST);
            }

            $packageJson = file_get_contents($packagePath);
            $package = $packageJson !== false ? Json::decode($packageJson) : null;

            if (!is_array($package)) {
                $this->logError('Unable to read or parse package.json.');
                return $this->npmInfoResult(NpmStatus::UNREADABLE_MANIFEST);
            }
        } catch (\Throwable $e) {
            $this->logError('Error reading the npm manifest: ' . $e->getMessage());
            return $this->npmInfoResult(NpmStatus::UNREADABLE_MANIFEST);
        }

        // A non-array value here is malformed, and must cost only that map rather than the section.
        $declared = is_array($package['dependencies'] ?? null) ? $package['dependencies'] : [];
        $devDeclared = is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [];

        try {
            $npmLockPath = $root . DIRECTORY_SEPARATOR . 'package-lock.json';
            $yarnLockPath = $root . DIRECTORY_SEPARATOR . 'yarn.lock';
            $pnpmLockPath = $root . DIRECTORY_SEPARATOR . 'pnpm-lock.yaml';

            // Only npm lockfiles are parsed. yarn and pnpm lockfiles are detected and named so that the
            // distinction is expressible downstream, but their declared packages report a null version.
            if (is_file($yarnLockPath) && !is_file($npmLockPath)) {
                return $this->npmInfoResult(
                    NpmStatus::UNSUPPORTED_LOCKFILE,
                    'yarn',
                    $this->fileUpdatedAt($yarnLockPath),
                    $declared,
                    $devDeclared,
                );
            }

            if (is_file($pnpmLockPath) && !is_file($npmLockPath)) {
                return $this->npmInfoResult(
                    NpmStatus::UNSUPPORTED_LOCKFILE,
                    'pnpm',
                    $this->fileUpdatedAt($pnpmLockPath),
                    $declared,
                    $devDeclared,
                );
            }

            if (!is_file($npmLockPath)) {
                return $this->npmInfoResult(NpmStatus::NO_LOCKFILE, null, null, $declared, $devDeclared);
            }

            $lock = $this->getNpmLock($npmLockPath);

            return $this->npmInfoResult(
                $lock === null ? NpmStatus::UNREADABLE_LOCKFILE : NpmStatus::OK,
                'npm',
                $this->fileUpdatedAt($npmLockPath),
                $declared,
                $devDeclared,
                $lock,
            );
        } catch (\Throwable $e) {
            // The manifest was read, so the declared packages are still reported. Only their resolved
            // versions are lost.
            $this->logError('Error reading the npm lockfile: ' . $e->getMessage());
            return $this->npmInfoResult(NpmStatus::UNREADABLE_LOCKFILE, null, null, $declared, $devDeclared);
        }
    }

    /**
     * Builds the npm section of the report.
     *
     * @param array<mixed> $declared Declared production dependencies from package.json.
     * @param array<mixed> $devDeclared Declared development dependencies from package.json.
     * @param array<mixed>|null $lock The decoded npm lockfile, or null when there is nothing to resolve against.
     * @return array{status: string, package_manager: string|null, lock_updated: string|null, dependencies: object, dev_dependencies: object}
     */
    protected function npmInfoResult(
        NpmStatus $status,
        ?string $packageManager = null,
        ?string $lockUpdated = null,
        array $declared = [],
        array $devDeclared = [],
        ?array $lock = null,
    ): array {
        return [
            'status' => $status->value,
            'package_manager' => $packageManager,
            'lock_updated' => $lockUpdated,
            'dependencies' => (object)$this->mapNpmDependencies($declared, $lock),
            'dev_dependencies' => (object)$this->mapNpmDependencies($devDeclared, $lock),
        ];
    }

    /**
     * @return array<mixed>|null The decoded lockfile, or null if it cannot be read or parsed.
     */
    protected function getNpmLock(string $lockPath): ?array
    {
        try {
            $lockJson = file_get_contents($lockPath);
            $lock = $lockJson !== false ? Json::decode($lockJson) : null;

            return is_array($lock) ? $lock : null;
        } catch (\Throwable $e) {
            $this->logError('Error parsing package-lock.json: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Maps declared packages to their resolved versions.
     *
     * Only top-level installs are matched, so a nested transitive copy of a package cannot clobber the
     * version of a package the project declares itself.
     *
     * @param array<mixed> $declared Package name => declared version constraint.
     * @param array<mixed>|null $lock The decoded lockfile, or null when no version can be resolved.
     * @return array<string, array{constraint: string|null, version: string|null}>
     */
    protected function mapNpmDependencies(array $declared, ?array $lock): array
    {
        return collect($declared)
            ->filter(function($constraint, $name) {
                if (is_string($constraint)) {
                    return true;
                }

                $this->logError("Skipping malformed npm dependency '$name': expected a string constraint.");
                return false;
            })
            ->mapWithKeys(function(string $constraint, string $name) use ($lock) {
                $version = $lock['packages']['node_modules/' . $name]['version']
                    ?? $lock['dependencies'][$name]['version']
                    ?? null;

                return [
                    $name => [
                        'constraint' => $this->stripUrlCredentials($constraint),
                        'version' => is_string($version) ? $this->stripUrlCredentials($version) : null,
                    ],
                ];
            })
            ->toArray();
    }

    /**
     * Removes credentials from a URL value, keeping the conventional git@ SSH user.
     *
     * The character class runs to the last @ in the authority so that a password containing an @
     * is stripped in full. A / still bounds the match, so it cannot run into the path.
     */
    protected function stripUrlCredentials(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('#(://)(?!git@)[^/\s]+@#', '$1', $value) ?? null;
    }


    /**
     * Logs an error. Overridable so that collection failures can be observed in tests.
     */
    protected function logError(string $message): void
    {
        PhoneHome::error($message);
    }

    protected function fileUpdatedAt(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        return $mtime !== false ? date('c', $mtime) : null;
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
            array_keys(Craft::$app->getPlugins()->getAllPluginInfo())
        );

        $modules = [];

        foreach (Craft::$app->modules as $handle => $module) {
            if (in_array($handle, $nonPluginModuleHandles, true)) {
                $modules[$handle] = [
                    'class' => is_object($module) ? get_class($module) : $module,
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
                        'release_date' => $release->date?->format('c'),
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
                            'release_date' => $release->date?->format('c'),
                        ];
                    }

                    PhoneHome::info("Added " . count($pluginData->releases) . " updates for plugin $pluginHandle");
                }
            }
        } catch (\Throwable $e) {
            PhoneHome::error('Error extracting detailed update info: ' . $e->getMessage());
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

    private function getStatusChecks(): array
    {
        // Create and trigger the event to allow registration of status checks
        $event = new RegisterStatusChecksEvent();
        $this->trigger(self::EVENT_REGISTER_STATUS_CHECKS, $event);

        // Collect results from all registered checks
        $results = [];
        foreach ($event->checks as $checkClass) {
            /** @var StatusCheckInterface $checkClass */
            $results[] = $checkClass::check();
        }

        return $results;
    }
}
