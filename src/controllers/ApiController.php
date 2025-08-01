<?php

namespace zaengle\phonehome\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;
use zaengle\phonehome\PhoneHome;

class ApiController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $this->checkToken();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $expandPhpInfo = $this->request->getBodyParam('expandPhpInfo', false);

        return $this->asJson(PhoneHome::$plugin->report->getInfo($expandPhpInfo));
    }

    /**
     * @throws UnauthorizedHttpException
     * @throws MethodNotAllowedHttpException
     * @throws BadRequestHttpException
     */
    public function actionSchema(): Response
    {
        $this->checkToken();

        if ($this->request->method !== 'GET') {
            throw new MethodNotAllowedHttpException('This endpoint only accepts GET requests');
        }
        $this->requireAcceptsJson();

        return $this->asJson(PhoneHome::getSchema());
    }

    /**
     * @throws UnauthorizedHttpException
     */
    protected function checkToken(): void
    {
        $headers = Craft::$app->getRequest()->getHeaders();
        $token = $headers->get('X-Auth-Token');

        if (!$token) {
            if (App::devMode()) {
                throw new UnauthorizedHttpException('A token is required');
            }
            // be a tiny bit more stealthy outside of dev mode
            throw new NotFoundHttpException();
        }

        if ($token !== PhoneHome::$plugin->getSettings()->getToken()) {
            throw new UnauthorizedHttpException('Invalid token');
        }
    }
}
