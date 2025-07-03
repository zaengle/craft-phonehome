<?php

namespace zaengle\phonehome;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;
use zaengle\phonehome\models\Settings;
use zaengle\phonehome\services\Report;
use zaengle\phonehome\traits\HasOwnLogfile;

/**
 * @property  Settings $settings
 * @property-read Report $report
 * @method    Settings getSettings()
 */
class PhoneHome extends BasePlugin
{
    use HasOwnLogfile;

    public bool $hasCpSettings = true;

    public static $plugin;

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
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['phone-home'] = 'phonehome/api/index';
            }
        );
    }
}
