<?php

use App\Models\AssetModel;
use App\Libraries\ScheduleService;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class DistributorAssetWorkflowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testDistributorLoginAndWebAccessAreRestrictedToOwnedAssets(): void
    {
        $fixture = $this->fixture();

        $createdAccount = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm('/control/operators', [
            'name' => 'New Film Partner', 'email' => 'new-film-partner@example.com',
            'role' => 'distributor', 'password' => 'New-Film-Partner-Password-2026!',
        ]);
        $createdAccount->assertRedirectTo('/control/operators');
        $this->assertSame('distributor', (new UserModel())->where('email', 'new-film-partner@example.com')->first()->role);

        $login = $this->postForm('/login', [
            'email' => 'distributor-one@example.com', 'password' => 'Distributor-One-Password-2026!',
        ]);
        $login->assertRedirectTo('/control/assets');

        $page = $this->withSession(['cms_web_user_id' => $fixture['distributorOneId']])->get('/control/assets');
        $page->assertOK();
        $page->assertSee('DISTRIBUTOR PORTAL');
        $page->assertSee('Distributor One Draft');
        $page->assertDontSee('Distributor Two Draft');
        $page->assertDontSee('Players');
        $page->assertDontSee('Schedules');
        $page->assertDontSee('Approve Film');
        $page->assertDontSee('Assign to Player');
        $page->assertDontSee('Delete Asset');

        $dashboard = $this->withSession(['cms_web_user_id' => $fixture['distributorOneId']])->get('/control');
        $dashboard->assertRedirectTo('/control/assets');

        $forbiddenApprove = $this->withSession(['cms_web_user_id' => $fixture['distributorOneId']])
            ->postForm('/control/assets/' . $fixture['assetOne']->public_id . '/approve', []);
        $forbiddenApprove->assertRedirectTo('/control/assets');
        $this->assertSame('draft', (new AssetModel())->find($fixture['assetOne']->id)->status);

        $forbiddenAssign = $this->withSession(['cms_web_user_id' => $fixture['distributorOneId']])
            ->postForm('/control/assets/' . $fixture['assetOne']->public_id . '/assign', ['device_id' => $fixture['device']->public_id]);
        $forbiddenAssign->assertRedirectTo('/control/assets');
        $this->assertSame(0, (new DeviceAssetModel())->where('asset_id', $fixture['assetOne']->id)->countAllResults());
    }

    public function testAdministratorApprovesOrRejectsDraftsBeforePlayerDistribution(): void
    {
        $fixture = $this->fixture();
        $assets = new AssetModel();

        $draftAssign = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->postForm('/control/assets/' . $fixture['assetOne']->public_id . '/assign', ['device_id' => $fixture['device']->public_id]);
        $draftAssign->assertRedirectTo('/control/assets');
        $this->assertSame(0, (new DeviceAssetModel())->where('asset_id', $fixture['assetOne']->id)->countAllResults());

        $rejected = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->postForm('/control/assets/' . $fixture['assetOne']->public_id . '/reject', [
                'rejection_reason' => 'Audio channel must be corrected before cinema distribution.',
            ]);
        $rejected->assertRedirectTo('/control/assets');
        $assetOne = $assets->find($fixture['assetOne']->id);
        $this->assertSame('rejected', $assetOne->status);
        $this->assertSame($fixture['adminId'], (int) $assetOne->reviewed_by);
        $this->assertNotNull($assetOne->reviewed_at);
        $this->assertSame('Audio channel must be corrected before cinema distribution.', $assetOne->rejection_reason);

        $ownerPage = $this->withSession(['cms_web_user_id' => $fixture['distributorOneId']])->get('/control/assets');
        $ownerPage->assertOK();
        $ownerPage->assertSee('REJECTED');
        $ownerPage->assertSee('Audio channel must be corrected');

        $approved = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->postForm('/control/assets/' . $fixture['assetTwo']->public_id . '/approve', []);
        $approved->assertRedirectTo('/control/assets');
        $assetTwo = $assets->find($fixture['assetTwo']->id);
        $this->assertSame('active', $assetTwo->status);
        $this->assertSame($fixture['adminId'], (int) $assetTwo->reviewed_by);

        $assigned = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->postForm('/control/assets/' . $fixture['assetTwo']->public_id . '/assign', ['device_id' => $fixture['device']->public_id]);
        $assigned->assertRedirectTo('/control/assets');
        $this->assertNotNull((new DeviceAssetModel())->where('asset_id', $assetTwo->id)->where('device_id', $fixture['device']->id)->first());

        $inventory = new DeviceAssetModel();
        $activeAssignment = $inventory->where('asset_id', $assetTwo->id)->where('device_id', $fixture['device']->id)->first();
        $inventory->update($activeAssignment->id, ['status' => 'ready']);
        $inventory->insert([
            'device_id' => $fixture['device']->id, 'asset_id' => $assetOne->id,
            'media_key' => 'managed:' . $assetOne->public_id, 'source' => 'managed',
            'title' => $assetOne->title, 'filename' => $assetOne->filename,
            'relative_path' => $assetOne->filename, 'size_bytes' => $assetOne->size_bytes,
            'duration_ms' => $assetOne->duration_ms, 'sha256' => $assetOne->sha256,
            'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $scheduleDevices = (new ScheduleService())->readyMediaByDevice();
        $scheduledMedia = array_column($scheduleDevices[0]['media'], 'title');
        $this->assertContains('Distributor Two Draft', $scheduledMedia);
        $this->assertNotContains('Distributor One Draft', $scheduledMedia);

        $manifest = $this->withHeaders(['Authorization' => 'Bearer ' . $fixture['deviceToken']])->get('/api/player/assets/assigned');
        $manifest->assertOK();
        $data = json_decode($manifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $data);
        $this->assertSame($assetTwo->public_id, $data[0]['id']);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $users = new UserModel();
        $adminId = $users->insert([
            'email' => 'workflow-admin@example.com', 'name' => 'Workflow Admin',
            'password_hash' => password_hash('Workflow-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $distributorOneId = $users->insert([
            'email' => 'distributor-one@example.com', 'name' => 'Distributor One',
            'password_hash' => password_hash('Distributor-One-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'distributor', 'status' => 'active',
        ], true);
        $distributorTwoId = $users->insert([
            'email' => 'distributor-two@example.com', 'name' => 'Distributor Two',
            'password_hash' => password_hash('Distributor-Two-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'distributor', 'status' => 'active',
        ], true);
        $deviceToken = 'distributor-workflow-device-token';
        $deviceId = (new DeviceModel())->insert([
            'public_id' => 'f1111111-2222-4333-8444-555555555555', 'name' => 'Cinema Studio 1',
            'location' => 'Jakarta', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
            'device_key_hash' => hash('sha256', $deviceToken),
        ], true);
        $device = (new DeviceModel())->find($deviceId);
        $assetOneId = (new AssetModel())->insert([
            'public_id' => 'f2222222-2222-4333-8444-555555555555', 'title' => 'Distributor One Draft',
            'filename' => 'one.mp4', 'storage_key' => 'assets/one.mp4', 'mime_type' => 'video/mp4',
            'size_bytes' => 1024, 'sha256' => str_repeat('1', 64), 'duration_ms' => 60000,
            'status' => 'draft', 'created_by' => $distributorOneId,
        ], true);
        $assetTwoId = (new AssetModel())->insert([
            'public_id' => 'f3333333-2222-4333-8444-555555555555', 'title' => 'Distributor Two Draft',
            'filename' => 'two.mp4', 'storage_key' => 'assets/two.mp4', 'mime_type' => 'video/mp4',
            'size_bytes' => 2048, 'sha256' => str_repeat('2', 64), 'duration_ms' => 90000,
            'status' => 'draft', 'created_by' => $distributorTwoId,
        ], true);
        $assetOne = (new AssetModel())->find($assetOneId);
        $assetTwo = (new AssetModel())->find($assetTwoId);
        return compact('adminId', 'distributorOneId', 'distributorTwoId', 'deviceToken', 'device', 'assetOne', 'assetTwo');
    }

    /** @param array<string, mixed> $data */
    private function postForm(string $uri, array $data)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withHeaders(['Cookie' => $security->cookieName . '=' . $token])
            ->post($uri, [...$data, $security->tokenName => $token]);
    }
}
