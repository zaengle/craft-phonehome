<?php
namespace zaengle\phonehome\tests\functional;

use Craft;
use craft\test\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class ApiEndpointTest extends TestCase
{
    protected $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a Guzzle client for testing API requests
        $this->client = new Client([
            'base_uri' => 'http://localhost/', // Adjust to your test environment
            'http_errors' => false,
        ]);

        // Set token for testing
        putenv('PHONE_HOME_TOKEN=test-token');
    }

    public function testEndpointRequiresPostMethod(): void
    {
        $response = $this->client->request('GET', 'phone-home', [
            'headers' => [
                'X-Auth-Token' => 'test-token',
            ],
        ]);

        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testEndpointRequiresValidToken(): void
    {
        $response = $this->client->request('POST', 'phone-home', [
            'headers' => [
                'X-Auth-Token' => 'invalid-token',
            ],
        ]);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testEndpointReturnsValidData(): void
    {
        $response = $this->client->request('POST', 'phone-home', [
            'headers' => [
                'X-Auth-Token' => 'test-token',
            ],
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);

        // Assert response structure
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('craft_version', $data);
        $this->assertArrayHasKey('system', $data);
        $this->assertArrayHasKey('plugins', $data);
    }
}
