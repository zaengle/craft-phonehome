<?php
namespace zaengle\phonehome\tests\unit;

use Craft;
use zaengle\phonehome\controllers\ApiHandlerFactory;
use zaengle\phonehome\controllers\Craft4ApiHandler;
use zaengle\phonehome\controllers\Craft5ApiHandler;
use zaengle\phonehome\tests\TestCase;

class ApiHandlerFactoryTest extends TestCase
{
    public function testCreateReturnsCraft4Handler(): void
    {
        // Mock Craft::$app to return version 4.x
        Craft::$app = $this->createMock(\craft\web\Application::class);
        Craft::$app->method('getVersion')->willReturn('4.5.0');

        $handler = ApiHandlerFactory::create();

        $this->assertInstanceOf(Craft4ApiHandler::class, $handler);
    }

    public function testCreateReturnsCraft5Handler(): void
    {
        // Mock Craft::$app to return version 5.x
        Craft::$app = $this->createMock(\craft\web\Application::class);
        Craft::$app->method('getVersion')->willReturn('5.0.0');

        $handler = ApiHandlerFactory::create();

        $this->assertInstanceOf(Craft5ApiHandler::class, $handler);
    }
}
