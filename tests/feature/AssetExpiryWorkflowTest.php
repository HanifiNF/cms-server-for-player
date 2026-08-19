<?php

use App\Libraries\AssetExpiryService;
use App\Libraries\ScheduleService;
use App\Libraries\ScheduleValidationException;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** @internal */
final class AssetExpiryWorkflowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testExpiryQueuesPlayerRemovalAndBumpsRevisionsOnlyOnce(): void
    {
        $fixture = $this->fixture('2026-08-19');
        $service = new AssetExpiryService();
        $now = new DateTimeImmutable('2026-08-20 00:00:01', new DateTimeZone('Asia/Jakarta'));

        $result = $service->expireDue($now);
        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['assignments']);
        $this->assertSame('expired', (new AssetModel())->find($fixture['assetId'])->status);
        $this->assertSame('removal_pending', (new DeviceAssetModel())->find($fixture['assignmentId'])->status);
        $device = (new DeviceModel())->find($fixture['deviceId']);
        $this->assertSame(1, $device->asset_revision);
        $this->assertSame(1, $device->schedule_revision);

        $second = $service->expireDue($now->modify('+1 hour'));
        $this->assertSame(0, $second['expired']);
        $device = (new DeviceModel())->find($fixture['deviceId']);
        $this->assertSame(1, $device->asset_revision);
        $this->assertSame(1, $device->schedule_revision);

        $manifest = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])
            ->get('/api/player/assets/assigned');
        $manifest->assertOK();
        $this->assertSame([], json_decode($manifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']);

        $removals = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])
            ->get('/api/player/assets/removals');
        $removals->assertOK();
        $removalData = json_decode($removals->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame($fixture['publicId'], $removalData[0]['id']);

        $ack = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])
            ->withBodyFormat('json')->post('/api/player/assets/' . $fixture['publicId'] . '/removed', []);
        $ack->assertOK();
        $this->assertNull((new DeviceAssetModel())->find($fixture['assignmentId']));
        $this->assertNotNull((new AssetModel())->find($fixture['assetId']));
    }

    public function testHeartbeatProcessesDueExpiryAndReturnsDistributionRevision(): void
    {
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->modify('-1 day')->format('Y-m-d');
        $fixture = $this->fixture($yesterday);

        $heartbeat = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])
            ->withBodyFormat('json')->post('/api/player/heartbeat', ['timezone' => 'Asia/Jakarta']);
        $heartbeat->assertOK();
        $payload = json_decode($heartbeat->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame(1, $payload['asset_revision']);
        $this->assertSame('expired', (new AssetModel())->find($fixture['assetId'])->status);
    }

    public function testFilmRemainsActiveThroughItsValidUntilDate(): void
    {
        $fixture = $this->fixture('2026-08-20');
        $result = (new AssetExpiryService())->expireDue(
            new DateTimeImmutable('2026-08-20 23:59:59', new DateTimeZone('Asia/Jakarta')),
        );

        $this->assertSame(0, $result['expired']);
        $this->assertSame('active', (new AssetModel())->find($fixture['assetId'])->status);
        $this->assertSame('ready', (new DeviceAssetModel())->find($fixture['assignmentId'])->status);
    }

    public function testScheduleCannotCrossManagedFilmExpiration(): void
    {
        $timezone = new DateTimeZone('Asia/Jakarta');
        $expiresOn = (new DateTimeImmutable('now', $timezone))->modify('+2 days')->format('Y-m-d');
        $fixture = $this->fixture($expiresOn);
        $startAfterExpiry = (new DateTimeImmutable($expiresOn . ' 12:00:00', $timezone))
            ->modify('+1 day')->format('Y-m-d\TH:i');

        $payload = [
            'title' => 'Invalid licensed screening', 'description' => '',
            'device_id' => $fixture['devicePublicId'], 'start_at' => $startAfterExpiry,
            'recurrence' => 'one_time', 'days_of_week' => [], 'recurrence_until' => '',
            'priority' => 0, 'loop_enabled' => '0',
            'media_keys' => ['managed:' . $fixture['publicId']], 'duration_ms' => ['60000'],
        ];
        $this->expectException(ScheduleValidationException::class);
        (new ScheduleService())->create($payload, $fixture['adminId']);
    }

    /** @return array<string, mixed> */
    private function fixture(string $expiresOn): array
    {
        $adminId = (new UserModel())->insert([
            'email' => 'expiry-admin-' . bin2hex(random_bytes(4)) . '@example.com', 'name' => 'Expiry Admin',
            'password_hash' => password_hash('Expiry-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $token = 'expiry-token-' . bin2hex(random_bytes(8));
        $devicePublicId = 'a' . substr(bin2hex(random_bytes(18)), 1, 7) . '-1111-4111-8111-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $deviceId = (new DeviceModel())->insert([
            'public_id' => $devicePublicId, 'name' => 'Expiry Player', 'location' => 'Jakarta',
            'timezone' => 'Asia/Jakarta', 'status' => 'active', 'device_key_hash' => hash('sha256', $token),
        ], true);
        $publicId = 'b' . substr(bin2hex(random_bytes(18)), 1, 7) . '-2222-4222-8222-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $assetId = (new AssetModel())->insert([
            'public_id' => $publicId, 'title' => 'Licensed Film', 'filename' => 'licensed.mp4',
            'storage_key' => 'assets/licensed.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64), 'duration_ms' => 60000, 'status' => 'active',
            'expires_on' => $expiresOn, 'created_by' => $adminId,
        ], true);
        $assignmentId = (new DeviceAssetModel())->insert([
            'device_id' => $deviceId, 'asset_id' => $assetId, 'media_key' => 'managed:' . $publicId,
            'source' => 'managed', 'title' => 'Licensed Film', 'filename' => 'licensed.mp4',
            'relative_path' => 'licensed.mp4', 'size_bytes' => 1024, 'duration_ms' => 60000,
            'sha256' => str_repeat('a', 64), 'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ], true);
        return compact('adminId', 'token', 'deviceId', 'devicePublicId', 'publicId', 'assetId', 'assignmentId');
    }
}
