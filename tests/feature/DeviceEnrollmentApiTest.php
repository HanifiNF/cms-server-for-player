<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\AssetModel;
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
            'ldg_version'       => 'ldg-v1',
        ];

        $registration = $this->withBodyFormat('json')->post('/api/player/register', $registrationPayload);
        $registration->assertStatus(201);
        $registrationData = json_decode($registration->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertNotSame('', $registrationData['token']);
        $this->assertSame($enrollmentData['device']['id'], $registrationData['device_id']);
        $this->assertArrayHasKey('realtime_url', $registrationData);
        $this->assertArrayHasKey('realtime_enabled', $registrationData);

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
            'ldg_version' => 'ldg-v1',
        ]);
        $heartbeat->assertStatus(200);
        $heartbeatData = json_decode($heartbeat->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertArrayHasKey('realtime_url', $heartbeatData);
        $this->assertArrayHasKey('realtime_enabled', $heartbeatData);
        $heartbeat->assertJSONFragment(['data' => [
            'device_id'         => $registrationData['device_id'],
            'device_name'       => 'Test Lobby Player',
            'device_timezone'   => 'Asia/Jakarta',
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
        $this->assertSame('ldg-v1', $deviceList[0]['ldg_version']);
        $this->assertSame('ldg-v1', (new DeviceModel())->where('public_id', $registrationData['device_id'])->first()->ldg_version);

        $stillValidToken = $this->withHeaders([
            'Authorization' => 'Bearer ' . $registrationData['token'],
        ])->withBodyFormat('json')->post('/api/player/heartbeat', []);
        $stillValidToken->assertStatus(200);
    }

    public function testPlayerAssetSnapshotIsReconciledAndMissingFilesAreRetained(): void
    {
        $token = 'asset-sync-device-token';
        $deviceId = (new DeviceModel())->insert([
            'public_id' => '11111111-2222-4333-8444-555555555555',
            'name' => 'Asset Sync Player',
            'device_key_hash' => hash('sha256', $token),
            'status' => 'active',
            'timezone' => 'Asia/Jakarta',
        ], true);
        $this->assertIsInt($deviceId);

        $missingToken = $this->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => []]);
        $missingToken->assertStatus(401);
        $missingToken->assertJSONFragment(['error' => ['code' => 'missing_player_token']]);

        $first = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => [
                [
                    'media_key' => 'local:' . str_repeat('a', 64), 'source' => 'local',
                    'title' => 'Campaign Film', 'filename' => 'Campaign Film.mp4',
                    'relative_path' => 'Campaign/Campaign Film.mp4', 'size_bytes' => 1024,
                    'duration_ms' => 125000, 'sha256' => null, 'status' => 'ready',
                    'modified_at' => '2026-08-06T10:00:00+07:00',
                ],
                [
                    'media_key' => 'managed:asset-123', 'source' => 'managed',
                    'title' => 'Downloaded Film', 'filename' => 'Downloaded Film.mp4',
                    'relative_path' => 'Downloaded Film.mp4', 'size_bytes' => 2048,
                    'duration_ms' => 240000, 'sha256' => str_repeat('b', 64), 'status' => 'ready',
                    'modified_at' => null,
                ],
            ]]);
        $first->assertStatus(200);
        $first->assertJSONFragment(['data' => [
            'inventory_revision' => 1, 'reported' => 2, 'inserted' => 2,
            'total' => 2, 'ready' => 2, 'missing' => 0,
        ]]);

        $second = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => [[
                'media_key' => 'local:' . str_repeat('a', 64), 'source' => 'local',
                'title' => 'Campaign Film Updated', 'filename' => 'Campaign Film.mp4',
                'relative_path' => 'Campaign/Campaign Film.mp4', 'size_bytes' => 1024,
                'duration_ms' => 126000, 'sha256' => null, 'status' => 'ready',
                'modified_at' => '2026-08-06T10:00:00+07:00',
            ]]]);
        $second->assertStatus(200);
        $second->assertJSONFragment(['data' => [
            'inventory_revision' => 2, 'reported' => 1, 'updated' => 1,
            'marked_missing' => 1, 'total' => 2, 'ready' => 1, 'missing' => 1,
        ]]);

        $records = (new DeviceAssetModel())->where('device_id', $deviceId)->orderBy('media_key')->findAll();
        $this->assertCount(2, $records);
        $this->assertSame('Campaign Film Updated', $records[0]->title);
        $this->assertSame('missing', $records[1]->status);

        $unsafe = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => [[
                'media_key' => 'local:' . str_repeat('c', 64), 'source' => 'local',
                'title' => 'Unsafe', 'filename' => 'Unsafe.mp4',
                'relative_path' => 'C:/Secret/Unsafe.mp4', 'size_bytes' => 1,
                'duration_ms' => 0, 'status' => 'ready',
            ]]]);
        $unsafe->assertStatus(422);
        $unsafe->assertJSONFragment(['error' => ['code' => 'validation_failed']]);
        $this->assertSame(2, (int) (new DeviceModel())->find($deviceId)->inventory_revision);
    }

    public function testAssignedAssetManifestAndDownloadAreScopedToThePlayer(): void
    {
        $assignedToken = 'assigned-player-token';
        $otherToken = 'other-player-token';
        $assignedDeviceId = (new DeviceModel())->insert([
            'public_id' => 'aaaaaaaa-2222-4333-8444-555555555555', 'name' => 'Assigned Player',
            'device_key_hash' => hash('sha256', $assignedToken), 'status' => 'active', 'timezone' => 'Asia/Jakarta',
        ], true);
        $otherDeviceId = (new DeviceModel())->insert([
            'public_id' => 'bbbbbbbb-2222-4333-8444-555555555555', 'name' => 'Other Player',
            'device_key_hash' => hash('sha256', $otherToken), 'status' => 'active', 'timezone' => 'Asia/Jakarta',
        ], true);
        $content = 'private test film';
        $publicId = 'cccccccc-2222-4333-8444-555555555555';
        $storageDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'assets';
        if (! is_dir($storageDir)) mkdir($storageDir, 0775, true);
        $filePath = $storageDir . DIRECTORY_SEPARATOR . $publicId . '.mp4';
        file_put_contents($filePath, $content);
        try {
            $assetId = (new AssetModel())->insert([
                'public_id' => $publicId, 'title' => 'Remote Film', 'filename' => 'Remote Film.mp4',
                'storage_key' => 'assets/' . $publicId . '.mp4', 'mime_type' => 'video/mp4',
                'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
                'duration_ms' => 30000, 'status' => 'active', 'revision' => 2,
            ], true);
            (new DeviceAssetModel())->insert([
                'device_id' => $assignedDeviceId, 'asset_id' => $assetId,
                'media_key' => 'managed:' . $publicId, 'source' => 'managed', 'title' => 'Remote Film',
                'filename' => 'Remote Film.mp4', 'relative_path' => 'Remote Film.mp4',
                'size_bytes' => strlen($content), 'duration_ms' => 30000,
                'sha256' => hash('sha256', $content), 'status' => 'missing',
                'last_reported_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $manifest = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])->get('/api/player/assets/assigned');
            $manifest->assertOK();
            $manifestData = json_decode($manifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertCount(1, $manifestData);
            $this->assertSame($publicId, $manifestData[0]['id']);
            $this->assertSame('Remote Film', $manifestData[0]['title']);
            $this->assertSame('Remote Film.mp4', $manifestData[0]['filename']);
            $this->assertSame(strlen($content), $manifestData[0]['size']);
            $this->assertSame(2, $manifestData[0]['revision']);

            $emptyManifest = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])->get('/api/player/assets/assigned');
            $emptyManifest->assertOK();
            $this->assertSame([], json_decode($emptyManifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']);

            $forbidden = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])->get('/api/player/assets/' . $publicId . '/download');
            $forbidden->assertStatus(404);
            $download = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])->get('/api/player/assets/' . $publicId . '/download');
            $download->assertStatus(200);
            $etag = '"' . hash('sha256', $content) . '"';
            $rangeDownload = $this->withHeaders([
                'Authorization' => 'Bearer ' . $assignedToken,
                'Range' => 'bytes=2-7', 'If-Range' => $etag,
            ])->get('/api/player/assets/' . $publicId . '/download');
            $rangeDownload->assertStatus(206);
            $rangeDownload->assertHeader('Accept-Ranges', 'bytes');
            $rangeDownload->assertHeader('Content-Range', 'bytes 2-7/' . strlen($content));
            $rangeDownload->assertHeader('Content-Length', '6');
            $rangeDownload->assertHeader('ETag', $etag);
            $invalidRange = $this->withHeaders([
                'Authorization' => 'Bearer ' . $assignedToken, 'Range' => 'bytes=999-',
            ])->get('/api/player/assets/' . $publicId . '/download');
            $invalidRange->assertStatus(416);
            $invalidRange->assertHeader('Content-Range', 'bytes */' . strlen($content));
            $changedAsset = $this->withHeaders([
                'Authorization' => 'Bearer ' . $assignedToken,
                'Range' => 'bytes=2-', 'If-Range' => '"different-etag"',
            ])->get('/api/player/assets/' . $publicId . '/download');
            $changedAsset->assertStatus(200);
            $changedAsset->assertHeaderMissing('Content-Range');

            (new AssetModel())->update($assetId, ['duration_ms' => 0]);
            $durationSync = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])
                ->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => [[
                    'media_key' => 'managed:' . $publicId, 'source' => 'managed', 'title' => 'raw-upload-name',
                    'filename' => 'raw-upload-name.ldg', 'relative_path' => 'raw-upload-name.ldg',
                    'size_bytes' => strlen($content), 'duration_ms' => 32100,
                    'sha256' => hash('sha256', $content), 'status' => 'ready', 'modified_at' => null,
                ]]]);
            $durationSync->assertOK();
            $this->assertSame(32100, (int) (new AssetModel())->find($assetId)->duration_ms);
            $catalogAssignment = (new DeviceAssetModel())->where('device_id', $assignedDeviceId)->where('asset_id', $assetId)->first();
            $this->assertSame('Remote Film', $catalogAssignment->title);
            $this->assertSame('Remote Film.mp4', $catalogAssignment->filename);

            $assignmentModel = new DeviceAssetModel();
            $assignedRow = $assignmentModel->where('device_id', $assignedDeviceId)->where('asset_id', $assetId)->first();
            $this->assertNotNull($assignedRow);
            $assignmentModel->update($assignedRow->id, ['status' => 'removal_pending']);
            $staleSnapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])
                ->withBodyFormat('json')->post('/api/player/assets/sync', ['assets' => [[
                    'media_key' => 'managed:' . $publicId, 'source' => 'managed', 'title' => 'Remote Film',
                    'filename' => 'Remote Film.mp4', 'relative_path' => 'Remote Film.mp4',
                    'size_bytes' => strlen($content), 'duration_ms' => 32100,
                    'sha256' => hash('sha256', $content), 'status' => 'ready', 'modified_at' => null,
                ]]]);
            $staleSnapshot->assertOK();
            $this->assertSame('removal_pending', $assignmentModel->find($assignedRow->id)->status);
            $pending = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])->get('/api/player/assets/removals');
            $pending->assertOK();
            $pendingData = json_decode($pending->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
            $this->assertSame($publicId, $pendingData[0]['id']);
            $manifestAfterRemoval = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])->get('/api/player/assets/assigned');
            $this->assertSame([], json_decode($manifestAfterRemoval->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']);
            $wrongAck = $this->withHeaders(['Authorization' => 'Bearer ' . $otherToken])
                ->withBodyFormat('json')->post('/api/player/assets/' . $publicId . '/removed', []);
            $wrongAck->assertStatus(404);
            $ack = $this->withHeaders(['Authorization' => 'Bearer ' . $assignedToken])
                ->withBodyFormat('json')->post('/api/player/assets/' . $publicId . '/removed', []);
            $ack->assertOK();
            $this->assertNull($assignmentModel->where('device_id', $assignedDeviceId)->where('asset_id', $assetId)->first());
        } finally {
            if (is_file($filePath)) unlink($filePath);
        }
    }
}
