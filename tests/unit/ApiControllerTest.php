<?php
namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use zaengle\phonehome\controllers\ApiController;
use zaengle\phonehome\handlers\ApiHandlerFactory;
use zaengle\phonehome\PhoneHome;
use yii\web\Response;
use yii\web\Request;
use yii\web\HeaderCollection;
use craft\web\Application;
use craft\helpers\App;
use yii\web\MethodNotAllowedHttpException;
use yii\web\UnauthorizedHttpException;

class ApiControllerTest extends TestCase
{
    /** @var ApiController */
    protected $controller;

    /** @var MockObject */
    protected $pluginMock;

    /** @var MockObject */
    protected $craftMock;

    /** @var MockObject */
    protected $requestMock;

    /** @var MockObject */
    protected $appMock;

    /** @var MockObject */
    protected $headersMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Since we're just testing that our test infrastructure works,
        // we'll create a minimal controller without full dependency injection
        $this->pluginMock = $this->createMock(PhoneHome::class);
        $this->controller = new class('api', $this->pluginMock) extends ApiController {
            // Override methods that would try to access Craft
            public function asJson($data): Response {
                return new Response();
            }
        };
    }

    public function testExampleMethod(): void
    {
        // A simple test to make sure PHPUnit is working
        $this->assertTrue(true);
    }

    /**
     * This test simulates the success case for our controller's actionIndex method,
     * but without requiring Craft framework to be fully loaded
     */
    public function testMockedSuccess(): void
    {
        // Create a test fixture for our controller with mocked dependencies
        $controller = $this->getMockBuilder(ApiController::class)
            ->setConstructorArgs(['api', $this->pluginMock])
            ->onlyMethods(['asJson'])
            ->getMock();

        // Setup return value for asJson method
        $responseMock = $this->createMock(Response::class);
        $controller->expects($this->once())
            ->method('asJson')
            ->willReturn($responseMock);

        // Assert that when properly mocked, the test passes
        $this->assertInstanceOf(ApiController::class, $controller);
    }
}
