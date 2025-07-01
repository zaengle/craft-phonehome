<?php
namespace zaengle\phonehome\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;
use yii\web\MethodNotAllowedHttpException;

class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        if (!Craft::$app->getRequest()->isPost) {
            throw new MethodNotAllowedHttpException('Method Not Allowed. Only POST requests are supported.');
        }

        // Get authorization header
        $headers = Craft::$app->getRequest()->getHeaders();
        $token = $headers->get('X-Auth-Token');

        // Verify the token matches the environment variable
        $expectedToken = App::env('PHONE_HOME_TOKEN');

        if (!$expectedToken || $token !== $expectedToken) {
            throw new UnauthorizedHttpException('Invalid token');
        }

        return $this->asJson(ApiHandlerFactory::create()->getInfo());
    }
}
