<?php
namespace zaengle\phonehome\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;
use yii\web\MethodNotAllowedHttpException;
use zaengle\phonehome\PhoneHome;

class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->checkToken();

        $expandPhpInfo = $this->request->getBodyParam('expandPhpInfo', false);

        return $this->asJson(PhoneHome::$plugin->report->getInfo($expandPhpInfo));
    }

    protected function checkToken(): void
    {
        $headers = Craft::$app->getRequest()->getHeaders();
        $token = $headers->get('X-Auth-Token');

        if (!$token) {
            throw new UnauthorizedHttpException('A token is required');
        }

        if ($token !== PhoneHome::$plugin->getSettings()->getToken()) {
            throw new UnauthorizedHttpException('Invalid token');
        }
    }
}
