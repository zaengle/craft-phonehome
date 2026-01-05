<?php

namespace zaengle\phonehome;

use Craft;
use craft\base\Plugin as BasePlugin;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;
use zaengle\phonehome\events\RegisterStatusChecksEvent;
use zaengle\phonehome\models\Settings;
use zaengle\phonehome\services\Report;
use zaengle\phonehome\statuschecks\QueueStatusCheck;
use zaengle\phonehome\traits\HasOwnLogFile;

/**
 * @property  Settings $settings
 * @property-read Report $report
 * @method    Settings getSettings()
 */
class PhoneHome extends BasePlugin
{
    use HasOwnLogFile;

    public bool $hasCpSettings = true;

    public static PhoneHome $plugin;

    /**
     * Get the API version from the JSON schema
     *
     * @return string The API version
     */
    public static function getApiVersion(): string
    {
        return self::getSchema()['version'] ?? '1.0.0';
    }

    public static function getSchema(): array
    {
        $schemaPath = self::getSchemaPath();

        $schemaContent = file_get_contents($schemaPath);
        return json_decode($schemaContent, true);
    }

    public static function getSchemaPath(): string
    {
        return Craft::getAlias('@zaengle/phonehome/schemas/PhonehomeApi.schema.json');
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app->request->isConsoleRequest) {
            $this->controllerNamespace = 'zaengle\\phonehome\\console\\controllers';
        } else {
            $this->controllerNamespace = 'zaengle\\phonehome\\controllers';
        }

        $this->attachEventHandlers();

        self::info('PhoneHome plugin initialized');
    }

    public static function config(): array
    {
        return [
            'components' => [
                'report' => Report::class,
            ],
        ];
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws Exception
     */
    protected function settingsHtml(): string
    {
        // Get and pre-validate the settings
        $settings = $this->getSettings();
        $settings->validate();

        return Craft::$app->view->renderTemplate(
            'phonehome/settings',
            [
                'settings' => $this->getSettings(),
                'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile(strtolower($this->handle))),
            ]
        );
    }

    protected function afterInstall(): void
    {
        $configSource = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.example.php';
        $configTarget = Craft::$app->getConfig()->configDir . DIRECTORY_SEPARATOR . $this->handle . '.php';

        if (!file_exists($configTarget)) {
            copy($configSource, $configTarget);
        }
    }

    private function attachEventHandlers(): void
    {
        // Register the built-in queue status check
        Event::on(
            Report::class,
            Report::EVENT_REGISTER_STATUS_CHECKS,
            function(RegisterStatusChecksEvent $event) {
                $event->checks[] = QueueStatusCheck::class;
            }
        );
    }
}
