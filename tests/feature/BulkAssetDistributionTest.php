<?php

use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Security;

/** @internal */
final class BulkAssetDistributionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testLocationAndManualStudioSelectionsAreMergedWithoutDuplicates(): void
    {
        $fixture = $this->fixture();
        $response = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/assign-selection',
            [
                'location_ids' => [$fixture['jakarta']->public_id],
                'device_ids' => [$fixture['jakartaOne']->public_id, $fixture['bogorOne']->public_id],
            ]
        );
        $response->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');

        $inventory = new DeviceAssetModel();
        $this->assertSame(3, $inventory->where('asset_id', $fixture['asset']->id)->countAllResults());
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['jakartaOne']->id)->asset_revision);
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['jakartaTwo']->id)->asset_revision);
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['bogorOne']->id)->asset_revision);

        $duplicate = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/assign-selection',
            ['location_ids' => [$fixture['jakarta']->public_id], 'device_ids' => [$fixture['bogorOne']->public_id]]
        );
        $duplicate->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');
        $this->assertSame(3, (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->countAllResults());
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['jakartaOne']->id)->asset_revision);

        $detail = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->get('/control/library/' . $fixture['asset']->public_id);
        $detail->assertOK();
        $detail->assertSee('Assigned Studios');
        $detail->assertSee('Global Assign');
        $detail->assertSee('Select complete Locations or individual Studios.');
        $detail->assertSee('assigned ');
        $detail->assertSee('JAKARTA');
        $detail->assertSee('2 Studio(s)');
    }

    public function testGlobalAssignmentIsSnapshotAndBulkUnassignSupportsRetainOrRemoval(): void
    {
        $fixture = $this->fixture();
        $global = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/assign-global', []
        );
        $global->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');
        $this->assertSame(3, (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->countAllResults());

        $newStudioId = (new DeviceModel())->insert([
            'public_id' => 'b0000000-0000-4000-8000-000000000005', 'name' => 'Bogor Studio 2',
            'location' => 'Bogor', 'location_id' => $fixture['bogor']->id,
            'timezone' => 'Asia/Jakarta', 'status' => 'active', 'ldg_version' => 'ldg-v1',
        ], true);
        $this->assertSame(3, (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->countAllResults(), 'A later Studio must not inherit a snapshot automatically.');

        $secondGlobal = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/assign-global', []
        );
        $secondGlobal->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');
        $this->assertSame(4, (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->countAllResults());
        $this->assertSame(1, (int) (new DeviceModel())->find($newStudioId)->asset_revision);

        $retain = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/unassign-selection',
            ['location_ids' => [$fixture['jakarta']->public_id], 'removal_mode' => 'retain']
        );
        $retain->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');
        $this->assertSame(2, (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->countAllResults());

        $remove = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/unassign-global',
            ['removal_mode' => 'remove']
        );
        $remove->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');
        $remaining = (new DeviceAssetModel())->where('asset_id', $fixture['asset']->id)->findAll();
        $this->assertCount(2, $remaining);
        foreach ($remaining as $assignment) $this->assertSame('removal_pending', $assignment->status);
    }

    public function testDistributionDisplayPaginatesFiveLocationGroups(): void
    {
        $fixture = $this->fixture();
        $locations = new LocationModel();
        $devices = new DeviceModel();
        foreach (range(3, 6) as $number) {
            $locationId = $locations->insert([
                'public_id' => sprintf('a0000000-0000-4000-8000-%012d', 10 + $number),
                'name' => 'Location ' . $number, 'code' => 'L' . $number,
                'timezone' => 'Asia/Jakarta', 'status' => 'active',
            ], true);
            $location = $locations->find($locationId);
            $devices->insert([
                'public_id' => sprintf('b0000000-0000-4000-8000-%012d', 10 + $number),
                'name' => 'Studio ' . $number, 'location' => $location->name,
                'location_id' => $location->id, 'timezone' => 'Asia/Jakarta',
                'status' => 'active', 'ldg_version' => 'ldg-v1',
            ]);
        }

        $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/assets/' . $fixture['asset']->public_id . '/assign-global', []
        )->assertRedirectTo('/control/library/' . $fixture['asset']->public_id . '#distribution');

        $firstPage = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->get('/control/library/' . $fixture['asset']->public_id);
        $firstPage->assertOK();
        $this->assertSame(5, substr_count($firstPage->response()->getBody(), 'class="distribution-location-group"'));
        $firstPage->assertSee('See more');

        $secondPage = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->get('/control/library/' . $fixture['asset']->public_id . '?assignment_page=2');
        $secondPage->assertOK();
        $this->assertSame(1, substr_count($secondPage->response()->getBody(), 'class="distribution-location-group"'));
        $secondPage->assertSee('Previous');
    }

    public function testStudioCanAssignMultipleAssetsWithOneRevisionAndRealtimeEvent(): void
    {
        $fixture = $this->fixture();
        $secondAssetId = (new AssetModel())->insert([
            'public_id' => 'c0000000-0000-4000-8000-000000000002', 'title' => 'Studio Side Trailer',
            'asset_type' => 'trailer', 'filename' => 'studio-side-trailer.ldg',
            'storage_key' => 'assets/studio-side-trailer.ldg', 'mime_type' => 'application/octet-stream',
            'size_bytes' => 8192, 'sha256' => str_repeat('d', 64), 'duration_ms' => 30000,
            'status' => 'active', 'created_by' => $fixture['adminId'], 'encryption_format' => 'ldg-v1',
        ], true);

        $assigned = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/locations/' . $fixture['bogor']->public_id . '/studios/' . $fixture['bogorOne']->public_id . '/assets',
            ['asset_ids' => [$fixture['asset']->public_id, 'c0000000-0000-4000-8000-000000000002', $fixture['asset']->public_id]]
        );
        $assigned->assertRedirectTo('/control/locations/' . $fixture['bogor']->public_id);
        $inventory = new DeviceAssetModel();
        $this->assertSame(2, $inventory->where('device_id', $fixture['bogorOne']->id)->countAllResults());
        $this->assertNotNull($inventory->where('device_id', $fixture['bogorOne']->id)->where('asset_id', $secondAssetId)->first());
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['bogorOne']->id)->asset_revision);
        $this->assertSame(1, Database::connect()->table('outbox_events')
            ->where('aggregate_id', $fixture['bogorOne']->id)->where('event_type', 'asset.revision.changed')->countAllResults());

        $duplicate = $this->withSession(['cms_web_user_id' => $fixture['adminId']])->postForm(
            '/control/locations/' . $fixture['bogor']->public_id . '/studios/' . $fixture['bogorOne']->public_id . '/assets',
            ['asset_ids' => [$fixture['asset']->public_id, 'c0000000-0000-4000-8000-000000000002']]
        );
        $duplicate->assertRedirectTo('/control/locations/' . $fixture['bogor']->public_id);
        $this->assertSame(2, (new DeviceAssetModel())->where('device_id', $fixture['bogorOne']->id)->countAllResults());
        $this->assertSame(1, (int) (new DeviceModel())->find($fixture['bogorOne']->id)->asset_revision);

        $page = $this->withSession(['cms_web_user_id' => $fixture['adminId']])
            ->get('/control/locations/' . $fixture['bogor']->public_id);
        $page->assertOK();
        $page->assertSee('Assign Assets');
        $page->assertSee('View Assets');
        $page->assertSee('Studio Side Trailer');
        $page->assertSee('Select all filtered');
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $adminId = (new UserModel())->insert([
            'email' => 'bulk-distribution-admin@example.com', 'name' => 'Distribution Admin',
            'password_hash' => password_hash('Bulk-Distribution-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $locationModel = new LocationModel();
        $jakartaId = $locationModel->insert([
            'public_id' => 'a0000000-0000-4000-8000-000000000001', 'name' => 'Jakarta',
            'code' => 'JKT', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $bogorId = $locationModel->insert([
            'public_id' => 'a0000000-0000-4000-8000-000000000002', 'name' => 'Bogor',
            'code' => 'BGR', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $inactiveId = $locationModel->insert([
            'public_id' => 'a0000000-0000-4000-8000-000000000003', 'name' => 'Bandung',
            'code' => 'BDG', 'timezone' => 'Asia/Jakarta', 'status' => 'inactive',
        ], true);
        $jakarta = $locationModel->find($jakartaId);
        $bogor = $locationModel->find($bogorId);

        $deviceModel = new DeviceModel();
        $createStudio = static function (DeviceModel $model, string $publicId, string $name, object $location): object {
            $id = $model->insert([
                'public_id' => $publicId, 'name' => $name, 'location' => $location->name,
                'location_id' => $location->id, 'timezone' => 'Asia/Jakarta',
                'status' => 'active', 'ldg_version' => 'ldg-v1',
            ], true);
            return $model->find($id);
        };
        $jakartaOne = $createStudio($deviceModel, 'b0000000-0000-4000-8000-000000000001', 'Jakarta Studio 1', $jakarta);
        $jakartaTwo = $createStudio($deviceModel, 'b0000000-0000-4000-8000-000000000002', 'Jakarta Studio 2', $jakarta);
        $bogorOne = $createStudio($deviceModel, 'b0000000-0000-4000-8000-000000000003', 'Bogor Studio 1', $bogor);
        $inactiveLocation = $locationModel->find($inactiveId);
        $createStudio($deviceModel, 'b0000000-0000-4000-8000-000000000004', 'Bandung Studio 1', $inactiveLocation);

        $assetId = (new AssetModel())->insert([
            'public_id' => 'c0000000-0000-4000-8000-000000000001', 'title' => 'Bulk Distribution Film',
            'filename' => 'bulk-film.ldg', 'storage_key' => 'assets/bulk-film.ldg',
            'mime_type' => 'application/octet-stream', 'size_bytes' => 4096,
            'sha256' => str_repeat('c', 64), 'duration_ms' => 60000,
            'status' => 'active', 'created_by' => $adminId, 'encryption_format' => 'ldg-v1',
        ], true);
        $asset = (new AssetModel())->find($assetId);
        return compact('adminId', 'asset', 'jakarta', 'bogor', 'jakartaOne', 'jakartaTwo', 'bogorOne');
    }

    /** @param array<string,mixed> $data */
    private function postForm(string $uri, array $data)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withHeaders(['Cookie' => $security->cookieName . '=' . $token])
            ->post($uri, [...$data, $security->tokenName => $token]);
    }
}
