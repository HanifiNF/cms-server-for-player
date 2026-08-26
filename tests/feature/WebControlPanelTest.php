<?php

use App\Models\DeviceModel;
use App\Models\DeviceAssetModel;
use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use App\Models\StorageProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class WebControlPanelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testControlPanelRequiresAnAdministratorSession(): void
    {
        $result = $this->get('/control');
        $result->assertRedirectTo('/login');
    }

    public function testFirstRunSetupCreatesTheOnlyInitialAdministrator(): void
    {
        $result = $this->postForm('/setup', [
            'name' => 'First Administrator', 'email' => 'first-admin@example.com',
            'password' => 'First-Admin-Password-2026!', 'password_confirmation' => 'First-Admin-Password-2026!',
        ]);
        $result->assertRedirectTo('/control');

        $admin = (new UserModel())->where('email', 'first-admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(password_verify('First-Admin-Password-2026!', $admin->password_hash));

        $closedSetup = $this->get('/setup');
        $closedSetup->assertRedirectTo('/login');
    }

    public function testAdministratorCanCreateOperatorAndAssignedPlayerFromGui(): void
    {
        $adminId = (new UserModel())->insert([
            'email' => 'web-admin@example.com', 'name' => 'Web Admin',
            'password_hash' => password_hash('Web-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $this->assertIsInt($adminId);

        $catalogPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/library');
        $catalogPage->assertOK();
        $catalogPage->assertSee('Add Asset');
        $catalogPage->assertSee('No matching media');
        $catalogPage->assertSee('Preparing upload');
        $catalogPage->assertSee('Cancel upload');
        $legacyAssetsPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/assets');
        $legacyAssetsPage->assertRedirectTo('/control/library');

        $security = config(Security::class);
        $csrf = csrf_hash();
        $ajaxFailure = $this->withSession(['cms_web_user_id' => $adminId])
            ->withHeaders([
                'Cookie' => $security->cookieName . '=' . $csrf,
                'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
            ])->post('/control/assets/upload', [$security->tokenName => $csrf]);
        $ajaxFailure->assertStatus(422);
        $ajaxFailure->assertJSONFragment(['error' => ['code' => 'asset_upload_failed']]);

        $operatorResponse = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/operators', [
            'name' => 'Lobby Operator', 'email' => 'lobby-operator@example.com',
            'role' => 'operator', 'password' => 'Lobby-Operator-Password-2026!',
        ]);
        $operatorResponse->assertRedirectTo('/control/operators');
        $operator = (new UserModel())->where('email', 'lobby-operator@example.com')->first();
        $this->assertNotNull($operator);
        $accountSearch = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/operators?q=Lobby&role=operator&status=active');
        $accountSearch->assertOK();
        $accountSearch->assertSee('Lobby Operator');
        $accountSearch->assertSee('1 accounts');

        $locationResponse = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/locations', [
            'name' => 'Lobby lantai 1', 'code' => 'LBY1', 'address' => '', 'timezone' => 'Asia/Jakarta',
        ]);
        $locationResponse->assertRedirectTo('/control/locations');
        $location = (new LocationModel())->where('code', 'LBY1')->first();
        $this->assertNotNull($location);
        $deviceResponse = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/locations/' . $location->public_id . '/studios', [
            'name' => 'Player Lobby',
            'timezone' => 'Asia/Jakarta', 'assigned_user_id' => $operator->id,
        ]);
        $deviceResponse->assertRedirectTo('/control/locations/' . $location->public_id);

        $device = (new DeviceModel())->where('name', 'Player Lobby')->first();
        $this->assertNotNull($device);
        $this->assertSame('pending', $device->status);
        $this->assertSame((int) $operator->id, (int) $device->assigned_user_id);

        $page = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/locations/' . $location->public_id);
        $page->assertOK();
        $page->assertSee('Player Lobby');
        $page->assertSee('Lobby Operator');

        (new DeviceModel())->update($device->id, [
            'status' => 'active', 'device_key_hash' => hash('sha256', 'device-token'),
            'fingerprint_hash' => hash('sha256', 'installation-id'),
        ]);
        $catalogAssetId = (new AssetModel())->insert([
            'public_id' => 'dddddddd-2222-4333-8444-555555555555', 'title' => 'CMS Campaign Film',
            'filename' => 'CMS Campaign Film.mp4', 'storage_key' => 'assets/campaign.mp4',
            'mime_type' => 'video/mp4', 'size_bytes' => 2048, 'sha256' => str_repeat('e', 64),
            'duration_ms' => 60000, 'status' => 'active', 'created_by' => $adminId,
        ], true);
        $assignedAsset = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/assets/dddddddd-2222-4333-8444-555555555555/assign', ['device_id' => $device->public_id]);
        $assignedAsset->assertRedirectTo('/control/assets');
        $this->assertNotNull((new DeviceAssetModel())->where('device_id', $device->id)->where('asset_id', $catalogAssetId)->first());
        $this->assertSame(1, (new DeviceModel())->find($device->id)->asset_revision);
        $catalogPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/library/dddddddd-2222-4333-8444-555555555555');
        $catalogPage->assertOK();
        $catalogPage->assertSee('CMS Campaign Film');
        $catalogPage->assertSee('Player Lobby');
        $catalogPage->assertSee('Assign Studios');
        $catalogPage->assertSee('Global Unassign');
        $catalogPage->assertSee('Delete Asset');

        $blockedDelete = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/assets/dddddddd-2222-4333-8444-555555555555/delete', []);
        $blockedDelete->assertRedirectTo('/control/assets');
        $this->assertNotNull((new AssetModel())->find($catalogAssetId));

        $storageDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'assets';
        if (! is_dir($storageDir)) mkdir($storageDir, 0775, true);
        $deletePublicId = 'eeeeeeee-2222-4333-8444-555555555555';
        $deletePath = $storageDir . DIRECTORY_SEPARATOR . $deletePublicId . '-r1.mp4';
        $deletePathTwo = $storageDir . DIRECTORY_SEPARATOR . $deletePublicId . '-r2.mp4';
        file_put_contents($deletePath, 'revision one');
        file_put_contents($deletePathTwo, 'revision two');
        $deleteAssetId = (new AssetModel())->insert([
            'public_id' => $deletePublicId, 'revision' => 2, 'title' => 'Disposable Film', 'filename' => 'Disposable Film v2.mp4',
            'storage_key' => 'assets/' . $deletePublicId . '-r2.mp4', 'mime_type' => 'video/mp4',
            'size_bytes' => 12, 'sha256' => hash('sha256', 'revision two'), 'duration_ms' => 1000,
            'status' => 'active', 'created_by' => $adminId,
        ], true);
        (new AssetVersionModel())->insertBatch([
            ['asset_id' => $deleteAssetId, 'revision' => 1, 'filename' => 'Disposable Film.mp4', 'storage_key' => 'assets/' . $deletePublicId . '-r1.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 12, 'sha256' => hash('sha256', 'revision one'), 'duration_ms' => 1000, 'status' => 'rejected', 'submitted_by' => $adminId],
            ['asset_id' => $deleteAssetId, 'revision' => 2, 'filename' => 'Disposable Film v2.mp4', 'storage_key' => 'assets/' . $deletePublicId . '-r2.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 12, 'sha256' => hash('sha256', 'revision two'), 'duration_ms' => 1000, 'status' => 'approved', 'submitted_by' => $adminId],
        ]);
        try {
            $deletedAsset = $this->withSession(['cms_web_user_id' => $adminId])
                ->postForm('/control/assets/' . $deletePublicId . '/delete', []);
            $deletedAsset->assertRedirectTo('/control/assets');
            $this->assertNull((new AssetModel())->find($deleteAssetId));
            $this->assertFileDoesNotExist($deletePath);
            $this->assertFileDoesNotExist($deletePathTwo);
        } finally {
            if (is_file($deletePath)) unlink($deletePath);
            if (is_file($deletePathTwo)) unlink($deletePathTwo);
        }

        (new DeviceAssetModel())->insert([
            'device_id' => $device->id, 'media_key' => 'local:' . str_repeat('d', 64),
            'source' => 'local', 'title' => 'Promo Jakarta', 'filename' => 'Promo Jakarta.mp4',
            'relative_path' => 'Campaign/Promo Jakarta.mp4', 'size_bytes' => 4096,
            'duration_ms' => 90000, 'status' => 'ready', 'last_reported_at' => '2026-08-06 04:00:00',
        ]);
        $assetPage = $this->withSession(['cms_web_user_id' => $adminId])
            ->get('/control/devices/' . $device->public_id . '/assets?q=Promo&status=ready&source=local');
        $assetPage->assertOK();
        $assetPage->assertSee('Promo Jakarta');
        $assetPage->assertSee('Campaign/Promo Jakarta.mp4');
        $assetPage->assertSee('Media Folder');

        $revoked = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/locations/' . $location->public_id . '/studios/' . $device->public_id . '/revoke', []);
        $revoked->assertRedirectTo('/control/locations/' . $location->public_id);
        $revokedDevice = (new DeviceModel())->find($device->id);
        $this->assertSame('revoked', $revokedDevice->status);
        $this->assertNull($revokedDevice->device_key_hash);
        $this->assertNull($revokedDevice->fingerprint_hash);

        $revokedPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/locations/' . $location->public_id);
        $revokedPage->assertOK();
        $revokedPage->assertSee('REVOKED');
        $revokedPage->assertSee('Delete');

        $deleted = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/locations/' . $location->public_id . '/studios/' . $device->public_id . '/delete', []);
        $deleted->assertRedirectTo('/control/locations/' . $location->public_id);
        $this->assertNull((new DeviceModel())->find($device->id));
    }

    public function testAdministratorCanManageStorageProfilesWithoutMovingExistingAssets(): void
    {
        $adminId = (new UserModel())->insert([
            'email' => 'storage-admin@example.com', 'name' => 'Storage Admin',
            'password_hash' => password_hash('Storage-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $page = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/storage');
        $page->assertOK();
        $page->assertSee('Storage Profiles');
        $page->assertSee('Local Storage');
        $page->assertSee('Company FTPS');
        $page->assertSee('Company SFTP');
        $page->assertSee('SFTP and FTPS adapters available');
        $page->assertSee('/sftpfiles/Testing(Hanif)');

        $root = 'storage/test-profile-' . bin2hex(random_bytes(4));
        try {
            $created = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/storage', [
                'name' => 'Test Archive', 'driver' => 'local', 'root' => $root,
            ]);
            $created->assertRedirectTo('/control/storage');
            $profile = (new StorageProfileModel())->where('name', 'Test Archive')->first();
            $this->assertNotNull($profile);
            $this->assertSame('healthy', $profile->last_test_status);

            $defaultChanged = $this->withSession(['cms_web_user_id' => $adminId])
                ->postForm('/control/storage/' . $profile->public_id . '/default', []);
            $defaultChanged->assertRedirectTo('/control/storage');
            $this->assertTrue((new StorageProfileModel())->find($profile->id)->is_default);
            $this->assertFalse((new StorageProfileModel())->where('name', 'Local Storage')->first()->is_default);

            $blockedDisable = $this->withSession(['cms_web_user_id' => $adminId])
                ->postForm('/control/storage/' . $profile->public_id . '/status', ['status' => 'disabled']);
            $blockedDisable->assertRedirectTo('/control/storage');
            $this->assertSame('active', (new StorageProfileModel())->find($profile->id)->status);
        } finally {
            $path = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $root);
            if (is_dir($path)) @rmdir($path);
        }
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
