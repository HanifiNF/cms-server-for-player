<?php

use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\ScheduleModel;
use App\Models\UserModel;
use App\Libraries\ScheduleService;
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
        $startAt = new \DateTimeImmutable('+15 minutes', new \DateTimeZone('Asia/Jakarta'));
        $startAt = $startAt->setTime((int) $startAt->format('H'), (int) $startAt->format('i'), 37);
        $start = $startAt->format('Y-m-d\TH:i:s');

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
            'gap_after_ms' => [10000, 5000],
        ], $fixture['adminId']);
        $created->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Jakarta Morning Playlist')->first();
        $this->assertNotNull($schedule);
        $this->assertSame(1, (int) $schedule->revision);
        $this->assertSame(145, $schedule->end_at->getTimestamp() - $schedule->start_at->getTimestamp());
        $items = Database::connect()->table('schedule_items')->where('schedule_id', $schedule->id)->orderBy('position')->get()->getResultArray();
        $this->assertCount(2, $items);
        $this->assertSame([$fixture['localKey'], $fixture['managedKey']], array_column($items, 'media_key'));
        $this->assertSame([45000, 90000], array_map('intval', array_column($items, 'duration_override_ms')));
        $this->assertSame([10000, 5000], array_map('intval', array_column($items, 'gap_after_ms')));
        $this->assertSame(['Local Campaign', 'Managed Campaign'], array_column($items, 'title_snapshot'));
        $this->assertSame(37, (int) $schedule->start_at->format('s'));

        $webSchedule = (new ScheduleService())->listForWeb()[0];
        $this->assertSame(135000, $webSchedule['timeline']['film_duration_ms']);
        $this->assertSame(10000, $webSchedule['timeline']['gap_duration_ms']);
        $this->assertSame(145000, $webSchedule['timeline']['total_duration_ms']);
        $this->assertSame(55000, $webSchedule['items'][1]['start_offset_ms']);
        $this->assertSame(145000, $webSchedule['items'][1]['content_end_offset_ms']);

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
        $this->assertSame(10000, $payload['schedules'][0]['playlist'][0]['gapAfterMs']);
        $this->assertSame(5000, $payload['schedules'][0]['playlist'][1]['gapAfterMs']);
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

    public function testAdjustedTimelineBoundaryPersistsAsTheExistingFilmGap(): void
    {
        $fixture = $this->fixture();
        $start = (new \DateTimeImmutable('+20 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i:s');

        $this->postForm('/control/schedules', [
            'title' => 'Twenty Minute Boundary', 'device_id' => $fixture['device']->public_id,
            'timezone' => 'Asia/Jakarta', 'start_at' => $start,
            'media_keys' => [$fixture['localKey'], $fixture['managedKey']],
            'duration_ms' => [60_000, 90_000],
            'gap_after_ms' => [1_200_000, 0],
        ], $fixture['adminId'])->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Twenty Minute Boundary')->first();
        $this->assertNotNull($schedule);
        $this->assertSame(1_350, $schedule->end_at->getTimestamp() - $schedule->start_at->getTimestamp());

        $items = Database::connect()->table('schedule_items')->where('schedule_id', $schedule->id)->orderBy('position')->get()->getResultArray();
        $this->assertSame(1_200_000, (int) $items[0]['gap_after_ms']);

        $snapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $playlist = json_decode($snapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']['schedules'][0]['playlist'];
        $this->assertSame(1_200_000, $playlist[0]['gapAfterMs']);
        $this->assertArrayNotHasKey('startTime', $playlist[1], 'No per-film start-time variable should be introduced.');
    }

    public function testFilmPlaybackStartOffsetControlsTimelineAndPlayerSeekContract(): void
    {
        $fixture = $this->fixture();
        $start = (new \DateTimeImmutable('+35 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i:s');

        $this->postForm('/control/schedules', [
            'title' => 'Start Inside Films', 'device_id' => $fixture['device']->public_id,
            'timezone' => 'Asia/Jakarta', 'start_at' => $start,
            'media_keys' => [$fixture['localKey'], $fixture['managedKey']],
            'duration_ms' => [60_000, 90_000],
            'playback_start_offset_ms' => [10_000, 30_000],
            'gap_after_ms' => [5_000, 0],
        ], $fixture['adminId'])->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Start Inside Films')->first();
        $this->assertNotNull($schedule);
        $this->assertSame(115, $schedule->end_at->getTimestamp() - $schedule->start_at->getTimestamp());

        $items = Database::connect()->table('schedule_items')->where('schedule_id', $schedule->id)->orderBy('position')->get()->getResultArray();
        $this->assertSame([10_000, 30_000], array_map('intval', array_column($items, 'playback_start_offset_ms')));

        $web = (new ScheduleService())->listForWeb()[0];
        $this->assertSame(110_000, $web['timeline']['film_duration_ms']);
        $this->assertSame(115_000, $web['timeline']['total_duration_ms']);

        $snapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $playlist = json_decode($snapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']['schedules'][0]['playlist'];
        $this->assertSame(50_000, $playlist[0]['durationMs']);
        $this->assertSame(10_000, $playlist[0]['startOffsetMs']);
        $this->assertSame(60_000, $playlist[1]['durationMs']);
        $this->assertSame(30_000, $playlist[1]['startOffsetMs']);

        $invalidStart = (new \DateTimeImmutable('+90 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i:s');
        $this->postForm('/control/schedules', [
            'title' => 'Invalid Start Offset', 'device_id' => $fixture['device']->public_id,
            'timezone' => 'Asia/Jakarta', 'start_at' => $invalidStart,
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [60_000],
            'playback_start_offset_ms' => [60_000],
        ], $fixture['adminId'])->assertRedirect();
        $this->assertSame(1, (new ScheduleModel())->countAllResults());
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

    public function testOneScheduleTargetsMultipleStudiosAtTheSameAbsoluteInstant(): void
    {
        $fixture = $this->fixture();
        $secondToken = 'schedule-second-player-token';
        $secondDevice = $this->additionalDevice($fixture, $secondToken, true, true);
        $start = (new \DateTimeImmutable('+20 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i');

        $created = $this->postForm('/control/schedules', [
            'title' => 'Synchronized Premiere',
            'device_ids' => [$fixture['device']->public_id, $secondDevice->public_id],
            'timezone' => 'Asia/Jakarta', 'start_at' => $start, 'priority' => 10,
            'media_keys' => [$fixture['localKey'], $fixture['managedKey']],
            'duration_ms' => [60000, 90000],
        ], $fixture['adminId']);
        $created->assertRedirectTo('/control/schedules');

        $schedule = (new ScheduleModel())->where('title', 'Synchronized Premiere')->first();
        $this->assertNotNull($schedule);
        $targets = Database::connect()->table('schedule_targets')->where('schedule_id', $schedule->id)->get()->getResultArray();
        $this->assertCount(2, $targets);
        $this->assertCount(1, (new ScheduleService())->listForWeb(), 'A multi-target Schedule must remain one Schedule in the CMS.');
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision);
        $this->assertSame(1, (int) (new DeviceModel())->find($secondDevice->id)->schedule_revision);

        $firstSnapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules');
        $secondSnapshot = $this->withHeaders(['Authorization' => 'Bearer ' . $secondToken])->get('/api/player/schedules');
        $firstPayload = json_decode($firstSnapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $secondPayload = json_decode($secondSnapshot->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $firstPayload['schedules']);
        $this->assertCount(1, $secondPayload['schedules']);
        $this->assertSame($firstPayload['schedules'][0]['id'], $secondPayload['schedules'][0]['id']);
        $this->assertSame($firstPayload['schedules'][0]['startTime'], $secondPayload['schedules'][0]['startTime']);
        $this->assertSame($firstPayload['schedules'][0]['playlist'], $secondPayload['schedules'][0]['playlist']);

        $updated = $this->postForm('/control/schedules/' . $schedule->public_id . '/update', [
            'title' => 'Synchronized Premiere', 'device_ids' => [$secondDevice->public_id],
            'timezone' => 'Asia/Jakarta', 'start_at' => $start, 'priority' => 10,
            'media_keys' => [$fixture['managedKey']], 'duration_ms' => [90000],
        ], $fixture['adminId']);
        $updated->assertRedirectTo('/control/schedules');
        $this->assertSame(2, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision, 'Removed targets must receive a new revision.');
        $this->assertSame(2, (int) (new DeviceModel())->find($secondDevice->id)->schedule_revision);

        $firstAfterUpdate = json_decode($this->withHeaders(['Authorization' => 'Bearer ' . $fixture['token']])->get('/api/player/schedules')->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $secondAfterUpdate = json_decode($this->withHeaders(['Authorization' => 'Bearer ' . $secondToken])->get('/api/player/schedules')->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame([], $firstAfterUpdate['schedules']);
        $this->assertCount(1, $secondAfterUpdate['schedules']);
    }

    public function testMultiStudioScheduleRequiresCommonReadyMediaAndChecksEveryTargetForConflicts(): void
    {
        $fixture = $this->fixture();
        $secondDevice = $this->additionalDevice($fixture, 'schedule-intersection-token', false, true);
        $start = (new \DateTimeImmutable('+25 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i');

        $missingOnSecond = $this->postForm('/control/schedules', [
            'title' => 'Invalid Shared Playlist',
            'device_ids' => [$fixture['device']->public_id, $secondDevice->public_id],
            'timezone' => 'Asia/Jakarta', 'start_at' => $start,
            'media_keys' => [$fixture['localKey']], 'duration_ms' => [60000],
        ], $fixture['adminId']);
        $missingOnSecond->assertRedirect();
        $this->assertSame(0, (new ScheduleModel())->countAllResults());

        $secondOnly = $this->postForm('/control/schedules', [
            'title' => 'Second Studio Exclusive', 'device_id' => $secondDevice->public_id,
            'timezone' => 'Asia/Jakarta', 'start_at' => $start,
            'media_keys' => [$fixture['managedKey']], 'duration_ms' => [90000],
        ], $fixture['adminId']);
        $secondOnly->assertRedirectTo('/control/schedules');

        $conflictOnSecond = $this->postForm('/control/schedules', [
            'title' => 'Conflict On One Of Two Studios',
            'device_ids' => [$fixture['device']->public_id, $secondDevice->public_id],
            'timezone' => 'Asia/Jakarta', 'start_at' => $start,
            'media_keys' => [$fixture['managedKey']], 'duration_ms' => [90000],
        ], $fixture['adminId']);
        $conflictOnSecond->assertRedirect();
        $this->assertSame(1, (new ScheduleModel())->countAllResults());
    }

    public function testAdminCanDisableAndDeleteMultipleSchedulesWithOneDeviceRevisionPerBulkAction(): void
    {
        $fixture = $this->fixture();
        foreach ([20, 40] as $minutes) {
            $this->postForm('/control/schedules', [
                'title' => 'Bulk ' . $minutes,
                'device_id' => $fixture['device']->public_id,
                'timezone' => 'Asia/Jakarta',
                'start_at' => (new \DateTimeImmutable('+' . $minutes . ' minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d\TH:i:s'),
                'media_keys' => [$fixture['managedKey']], 'duration_ms' => [90000],
            ], $fixture['adminId'])->assertRedirectTo('/control/schedules');
        }
        $schedules = (new ScheduleModel())->orderBy('id')->findAll();
        $ids = array_map(static fn ($schedule): string => $schedule->public_id, $schedules);
        $this->assertSame(2, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision);

        $disabled = $this->postForm('/control/schedules/bulk-disable', [
            'schedule_ids' => $ids, 'return_query' => 'status=upcoming&q=Bulk',
        ], $fixture['adminId']);
        $disabled->assertRedirectTo('/control/schedules?status=upcoming&q=Bulk');
        $this->assertSame(['disabled', 'disabled'], array_map(static fn ($schedule): string => $schedule->status, (new ScheduleModel())->orderBy('id')->findAll()));
        $this->assertSame(3, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision);

        $deleted = $this->postForm('/control/schedules/bulk-delete', ['schedule_ids' => $ids], $fixture['adminId']);
        $deleted->assertRedirectTo('/control/schedules');
        $this->assertSame(0, (new ScheduleModel())->countAllResults());
        $this->assertSame(4, (int) (new DeviceModel())->find($fixture['device']->id)->schedule_revision);
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
            'title' => 'raw-managed-upload', 'filename' => 'raw-managed-upload.ldg', 'relative_path' => 'raw-managed-upload.ldg',
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

    /** @param array<string, mixed> $fixture */
    private function additionalDevice(array $fixture, string $token, bool $withLocal, bool $withManaged): object
    {
        $deviceId = (new DeviceModel())->insert([
            'public_id' => 'cccccccc-2222-4333-8444-555555555555', 'name' => 'Player Bandung',
            'location' => 'Bandung', 'device_key_hash' => hash('sha256', $token),
            'status' => 'active', 'timezone' => 'Asia/Jakarta',
        ], true);
        $assets = new DeviceAssetModel();
        if ($withLocal) {
            $assets->insert([
                'device_id' => $deviceId, 'media_key' => $fixture['localKey'], 'source' => 'local',
                'title' => 'Local Campaign', 'filename' => 'local.mp4', 'relative_path' => 'local.mp4',
                'size_bytes' => 1024, 'duration_ms' => 60000, 'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        if ($withManaged) {
            $asset = (new AssetModel())->where('public_id', $fixture['assetPublicId'])->first();
            $assets->insert([
                'device_id' => $deviceId, 'asset_id' => $asset->id, 'media_key' => $fixture['managedKey'], 'source' => 'managed',
                'title' => 'raw-managed-upload', 'filename' => 'raw-managed-upload.ldg', 'relative_path' => 'raw-managed-upload.ldg',
                'size_bytes' => 2048, 'duration_ms' => 90000, 'sha256' => str_repeat('a', 64),
                'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        return (new DeviceModel())->find($deviceId);
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
