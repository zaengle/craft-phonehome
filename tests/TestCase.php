<?php
namespace zaengle\phonehome\tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mockery;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock for Craft's Craft class
     */
    protected function mockCraft()
    {
        $craftMock = Mockery::mock('alias:Craft');
        return $craftMock;
    }
}
