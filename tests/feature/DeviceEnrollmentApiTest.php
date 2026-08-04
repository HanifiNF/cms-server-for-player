<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Player;

/**
 * @internal
 */
final class DeviceEnrollmentApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    protected function tearDown(): void
    {
        config(Player::class)->enablePairingCode = false;
        parent::tearDown();
    }

    public function testAdminEndpointsRejectMissingAdminKey(): void
    {
        $result = $this->get('/api/admin/devices');

        $result->assertStatus(401);
        $result->assertJSONFragment(['error' => ['code' => 'invalid_admin_key']]);
    }

    public function testPlayerCanEnrollRegisterAndSendHeartbeat(): void
    {
        $config = config(Player::class);
        $config->enablePairingCode = true;
        $this->assertNotSame('', $config->adminApiKey);
        $this->assertGreaterThanOrEqual(32, strlen($config->enrollmentPepper));

        $enrollment = $this->withHeaders([
            'X-CMS-Admin-Key' => $config->adminApiKey,
        ])->withBodyFormat('json')->post('/api/admin/devices/enroll', [
            'name'     => 'Test Lobby Player',
            'timezone' => 'Asia/Jakarta',
        ]);

        $enrollment->assertStatus(201);
        $enrollmentData = json_decode($enrollment->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $enrollmentData['enrollment_code']);

        $registrationPayload = [
            'enrollment_code'   => $enrollmentData['enrollment_code'],
            'device_fingerprint'=> 'test-device-fingerprint-001',
            'app_version'       => '1.1.0',
            'platform'          => 'win32-x64',
            'timezone'          => 'Asia/Jakarta',
        ];

        $registration = $this->withBodyFormat('json')->post('/api/player/register', $registrationPayload);
        $registration->assertStatus(201);
        $registrationData = json_decode($registration->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertNotSame('', $registrationData['token']);
        $this->assertSame($enrollmentData['device']['id'], $registrationData['device_id']);

        $reusedCode = $this->withBodyFormat('json')->post('/api/player/register', [
            ...$registrationPayload,
            'device_fingerprint' => 'another-test-device-002',
        ]);
        $reusedCode->assertStatus(401);
        $reusedCode->assertJSONFragment(['error' => ['code' => 'invalid_or_expired_enrollment']]);

        $missingToken = $this->withBodyFormat('json')->post('/api/player/heartbeat', []);
        $missingToken->assertStatus(401);
        $missingToken->assertJSONFragment(['error' => ['code' => 'missing_player_token']]);

        $heartbeat = $this->withHeaders([
            'Authorization' => 'Bearer ' . $registrationData['token'],
        ])->withBodyFormat('json')->post('/api/player/heartbeat', [
            'app_version' => '1.1.1',
            'platform'    => 'win32-x64',
            'timezone'    => 'Asia/Jakarta',
        ]);
        $heartbeat->assertStatus(200);
        $heartbeat->assertJSONFragment(['data' => [
            'device_id'         => $registrationData['device_id'],
            'connection_status' => 'online',
        ]]);

        $devices = $this->withHeaders([
            'X-CMS-Admin-Key' => $config->adminApiKey,
        ])->get('/api/admin/devices');
        $devices->assertStatus(200);
        $deviceList = json_decode($devices->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $deviceList);
        $this->assertSame('Test Lobby Player', $deviceList[0]['name']);
        $this->assertSame('online', $deviceList[0]['connection_status']);
        $this->assertSame('1.1.1', $deviceList[0]['app_version']);

        $unregister = $this->withHeaders([
            'Authorization' => 'Bearer ' . $registrationData['token'],
        ])->withBodyFormat('json')->post('/api/player/unregister', []);
        $unregister->assertStatus(200);
        $unregister->assertJSONFragment(['data' => [
            'device_id' => $registrationData['device_id'],
            'status'    => 'revoked',
        ]]);

        $revokedToken = $this->withHeaders([
            'Authorization' => 'Bearer ' . $registrationData['token'],
        ])->withBodyFormat('json')->post('/api/player/heartbeat', []);
        $revokedToken->assertStatus(401);
        $revokedToken->assertJSONFragment(['error' => ['code' => 'invalid_player_token']]);
    }
}
