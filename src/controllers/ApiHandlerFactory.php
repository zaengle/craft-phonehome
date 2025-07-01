<?php
namespace zaengle\phonehome\controllers;

use Craft;

class ApiHandlerFactory
{
    /**
     * Create the appropriate API handler based on the Craft version
     */
    public static function create(): BaseApiHandler
    {
        if (version_compare(Craft::$app->getVersion(), '5.0', '>=')) {
            return new Craft5ApiHandler();
        }

        return new Craft4ApiHandler();
    }
}
