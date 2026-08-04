<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HealthEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testHealthEndpointReturnsServiceStatus(): void
    {
        $result = $this->get('/api/health');

        $result->assertStatus(200);
        $result->assertJSONFragment([
            'status'  => 'ok',
            'service' => 'wir-player-cms',
        ]);
        $result->assertHeader('Cache-Control');
        $this->assertStringContainsString(
            'no-store',
            $result->response()->getHeaderLine('Cache-Control'),
        );
    }
}
