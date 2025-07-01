<?php
namespace zaengle\phonehome\tests\unit;

use Craft;
use Mockery;
use ReflectionClass;
use zaengle\phonehome\controllers\BaseApiHandler;
use zaengle\phonehome\tests\TestCase;

class BaseApiHandlerTest extends TestCase
{
    /**
     * @var BaseApiHandler|Mockery\Mock
     */
    protected $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock implementation of the abstract class
        $this->handler = Mockery::mock(BaseApiHandler::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Mock the abstract method
        $this->handler->shouldReceive('getUpdatesInfo')
            ->andReturn(['craft' => false, 'plugins' => []]);
    }

    public function testGetInfoReturnsCorrectStructure(): void
    {
        // Mock system info methods
        $this->handler->shouldReceive('getSystemInfo')
            ->andReturn(['php' => ['name' => 'PHP', 'version' => '8.1.0']]);

        $this->handler->shouldReceive('getPluginsInfo')
            ->andReturn(['phonehome' => ['name' => 'PhoneHome']]);

        $this->handler->shouldReceive('getModulesInfo')
            ->andReturn([]);

        // Set environment variables for testing
        putenv('CRAFT_ENVIRONMENT=test');
        putenv('PHONE_HOME_CUSTOM_KEYS=TEST_KEY');
        putenv('TEST_KEY=test_value');

        $info = $this->handler->getInfo();

        // Assert the response has all required keys
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('craft_version', $info);
        $this->assertArrayHasKey('craft_edition', $info);
        $this->assertArrayHasKey('environment', $info);
        $this->assertArrayHasKey('dev_mode', $info);
        $this->assertArrayHasKey('timestamp', $info);
        $this->assertArrayHasKey('system', $info);
        $this->assertArrayHasKey('plugins', $info);
        $this->assertArrayHasKey('modules', $info);
        $this->assertArrayHasKey('updates', $info);
        $this->assertArrayHasKey('meta', $info);

        // Check custom env vars are included
        $this->assertEquals('test_value', $info['meta']['TEST_KEY']);
    }

    // Add tests for other methods like getSystemInfo, getPluginsInfo, etc.

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
