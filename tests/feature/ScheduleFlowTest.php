<?php

use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\ScheduleModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Security;

/** @internal */
final class ScheduleFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testAdminCreatesOrderedScheduleAndPlayerReceivesAuthenticatedSnapshot(): void
    {
        $fixture = $this->fixture();
        $start = (new \DateTimeImmutable('+15 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i');

        $page = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->get('/control/schedules');
        $page->assertOK();
        $page->assertSee('Create a playback schedule');
        $page->assertSee('Local Campaign');
        $page->assertSee('Managed Campaign');
        $page->assertDontSee('Missing Campaign');

        $created = $this->postForm('/control/schedules', [
            'title' => 'Jakarta Morning Playlist', 'device_id' => $fixture['device']->public_id,
            'start_at' => $start, 'priority' => 5,
            'media_keys' => [$fixture['localKey'], $fixture['managedKey']],
            'duration_ms' => [45000, 90000],
        ], $fixture['adminId']);
        $created->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Jakarta Morning Playlist')->first();
        $this->assertNotNull($schedule);
        $this->assertSame(1, (int) $schedule->revision);
        $items = Database::connect()->table('schedule_items')->where('schedule_id', $schedule->id)->orderBy('position')->get()->getResultArray();
        $this->assertCount(2, $items);
        $this->assertSame([$fixture['localKey'], $fixture['managedKey']], array_column($items, 'media_key'));
        $this->assertSame([45000, 90000], array_map('intval', array_column($items, 'duration_override_ms')));

        $device = (new DeviceModel())->find($fixture['device']->id);
        $this->assertSame(1, (int) $device->schedule_revision);
        $outbox = Database::connect()->table('outbox_events')->where('aggregate_id', $device->id)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('schedule.revision.changed', $outbox['event_type']);
        $outboxPayload = json_decode((string) $outbox['payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($device->public_id, $outboxPayload['device_id']);
        $this->assertSame(1, $outboxPayload['schedule_revision']);

        $unauthorized = $this->get('/api/player/schedules');
        $unauthorized->assertStatus(401);
        $unauthorized->assertJSONFragment(['error' => ['code' => 'missing_player_token']]);

        $snapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $snapshot->assertOK();
        $payload = json_decode($snapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame(1, $payload['revision']);
        $this->assertCount(1, $payload['schedules']);
        $this->assertSame('Jakarta Morning Playlist', $payload['schedules'][0]['title']);
        $this->assertSame($fixture['localKey'], $payload['schedules'][0]['playlist'][0]['mediaKey']);
        $this->assertArrayNotHasKey('assetId', $payload['schedules'][0]['playlist'][0]);
        $this->assertSame($fixture['assetPublicId'], $payload['schedules'][0]['playlist'][1]['assetId']);
        $this->assertStringEndsWith('+00:00', $payload['schedules'][0]['startTime']);

        $conflict = $this->postForm('/control/schedules', [
            'title' => 'Conflicting Playlist', 'device_id' => $fixture['device']->public_id,
            'start_at' => $start, 'media_keys' => [$fixture['localKey']], 'duration_ms' => [30000],
        ], $fixture['adminId']);
        $conflict->assertRedirect();
        $this->assertSame(1, (new ScheduleModel())->countAllResults());

        $disabled = $this->postForm('/control/schedules/' . $schedule->public_id . '/status', ['enabled' => '0'], $fixture['adminId']);
        $disabled->assertRedirectTo('/control/schedules');
        $disabledSnapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $disabledPayload = json_decode($disabledSnapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame(2, $disabledPayload['revision']);
        $this->assertSame([], $disabledPayload['schedules']);

        $deleted = $this->postForm('/control/schedules/' . $schedule->public_id . '/delete', [], $fixture['adminId']);
        $deleted->assertRedirectTo('/control/schedules');
        $this->assertNull((new ScheduleModel())->find($schedule->id));
        $this->assertSame(3, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision);
    }

    public function testDailyScheduleSurvivesItsFirstOccurrenceAndBlocksFutureConflict(): void
    {
        $fixture = $this->fixture();
        $timezone = new \DateTimeZone('Asia/Jakarta');
        $start = (new \DateTimeImmutable('yesterday 08:00', $timezone))->format('Y-m-d\TH:i');
        $until = (new \DateTimeImmutable('+3 days', $timezone))->format('Y-m-d');

        $created = $this->postForm('/control/schedules', [
            'title' => 'Daily Opening', 'device_id' => $fixture['device']->public_id,
            'start_at' => $start, 'recurrence' => 'daily', 'recurrence_until' => $until,
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [60000],
        ], $fixture['adminId']);
        $created->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Daily Opening')->first();
        $this->assertNotNull($schedule);
        $this->assertSame('daily', $schedule->recurrence);
        $config = json_decode((string) $schedule->recurrence_config, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($until, $config['until']);

        $snapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $snapshot->assertOK();
        $payload = json_decode($snapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $payload['schedules']);
        $this->assertSame('daily', $payload['schedules'][0]['recurrence']['freq']);
        $this->assertSame([], $payload['schedules'][0]['recurrence']['daysOfWeek']);
        $this->assertStringEndsWith('+07:00', $payload['schedules'][0]['startTime']);
        $this->assertStringContainsString('T23:59:59+07:00', $payload['schedules'][0]['recurrence']['until']);

        $conflictingStart = (new \DateTimeImmutable('tomorrow 08:00', $timezone))->format('Y-m-d\TH:i');
        $conflict = $this->postForm('/control/schedules', [
            'title' => 'One-time Collision', 'device_id' => $fixture['device']->public_id,
            'start_at' => $conflictingStart, 'recurrence' => 'one_time',
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [30000],
        ], $fixture['adminId']);
        $conflict->assertRedirect();
        $this->assertSame(1, (new ScheduleModel())->countAllResults());

        $tomorrowDay = (int) (new \DateTimeImmutable('tomorrow', $timezone))->format('N');
        $weeklyConflict = $this->postForm('/control/schedules', [
            'title' => 'Weekly Collision', 'device_id' => $fixture['device']->public_id,
            'start_at' => $start, 'recurrence' => 'weekly', 'days_of_week' => [$tomorrowDay],
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [30000],
        ], $fixture['adminId']);
        $weeklyConflict->assertRedirect();
        $this->assertSame(1, (new ScheduleModel())->countAllResults());

        $nonConflictStart = (new \DateTimeImmutable('yesterday 09:00', $timezone))->format('Y-m-d\TH:i');
        $weekly = $this->postForm('/control/schedules', [
            'title' => 'Weekly Follow-up', 'device_id' => $fixture['device']->public_id,
            'start_at' => $nonConflictStart, 'recurrence' => 'weekly', 'days_of_week' => [$tomorrowDay],
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [30000],
        ], $fixture['adminId']);
        $weekly->assertRedirectTo('/control/schedules');
        $this->assertSame(2, (new ScheduleModel())->countAllResults());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $adminId = (new UserModel())->insert([
            'email' => 'schedule-admin@example.com', 'name' => 'Schedule Admin',
            'password_hash' => password_hash('Schedule-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $token = 'schedule-player-token';
        $deviceId = (new DeviceModel())->insert([
            'public_id' => 'aaaaaaaa-2222-4333-8444-555555555555', 'name' => 'Player Jakarta',
            'location' => 'Jakarta', 'device_key_hash' => hash('sha256', $token),
            'status' => 'active', 'timezone' => 'Asia/Jakarta',
        ], true);
        $device = (new DeviceModel())->find($deviceId);
        $assetPublicId = 'bbbbbbbb-2222-4333-8444-555555555555';
        $assetId = (new AssetModel())->insert([
            'public_id' => $assetPublicId, 'title' => 'Managed Campaign', 'filename' => 'managed.mp4',
            'storage_key' => 'assets/managed.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 2048,
            'sha256' => str_repeat('a', 64), 'duration_ms' => 90000, 'status' => 'active', 'created_by' => $adminId,
        ], true);
        $localKey = 'local:' . str_repeat('b', 64);
        $managedKey = 'managed:' . $assetPublicId;
        $assetModel = new DeviceAssetModel();
        $assetModel->insert([
            'device_id' => $deviceId, 'media_key' => $localKey, 'source' => 'local',
            'title' => 'Local Campaign', 'filename' => 'local.mp4', 'relative_path' => 'local.mp4',
            'size_bytes' => 1024, 'duration_ms' => 60000, 'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $assetModel->insert([
            'device_id' => $deviceId, 'asset_id' => $assetId, 'media_key' => $managedKey, 'source' => 'managed',
            'title' => 'Managed Campaign', 'filename' => 'managed.mp4', 'relative_path' => 'managed.mp4',
            'size_bytes' => 2048, 'duration_ms' => 90000, 'sha256' => str_repeat('a', 64),
            'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $assetModel->insert([
            'device_id' => $deviceId, 'media_key' => 'local:' . str_repeat('c', 64), 'source' => 'local',
            'title' => 'Missing Campaign', 'filename' => 'missing.mp4', 'relative_path' => 'missing.mp4',
            'size_bytes' => 0, 'duration_ms' => 30000, 'status' => 'missing', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return compact('adminId', 'token', 'device', 'assetPublicId', 'localKey', 'managedKey');
    }

    /** @param array<string, mixed> $data */
    private function postForm(string $uri, array $data, int $adminId)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withSession(['cms_web_user_id' => $adminId])
            ->withHeaders(['Cookie' => $security->cookieName . '=' . $token])
            ->post($uri, [...$data, $security->tokenName => $token]);
    }
}
