<?php
namespace zaengle\phonehome;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use modules\phonehome\controllers\ApiController;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;
    public bool $hasCpSection = false;

    public function init()
    {
        parent::init();

        $this->controllerNamespace = 'zaengle\\phonehome\\controllers';

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['phone-home'] = 'phonehome/api/index';
            }
        );

        Craft::info(
            Craft::t('phonehome', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }
}
