<?php

use App\Libraries\AssetTaxonomyService;
use App\Libraries\ScheduleService;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\GenreModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class MediaLibraryTaxonomyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testAdminManagesGenresAndLibraryShowsStudioAvailabilityAndScheduleFilters(): void
    {
        $adminId = $this->user('library-admin@example.com', 'Library Admin', 'admin');
        $created = $this->postForm('/control/genres', ['name' => 'Historical Epic'], $adminId);
        $created->assertRedirectTo('/control/assets');
        $adventure = (new GenreModel())->where('slug', 'historical-epic')->first();
        $this->assertNotNull($adventure);

        $assetId = (new AssetModel())->insert([
            'public_id' => '61000000-2222-4333-8444-555555555555', 'title' => 'Mountain Preview',
            'asset_type' => 'trailer', 'filename' => 'mountain-preview.mp4', 'storage_key' => 'assets/mountain.ldg',
            'mime_type' => 'video/mp4', 'size_bytes' => 4096, 'sha256' => str_repeat('a', 64),
            'duration_ms' => 90000, 'status' => 'active', 'created_by' => $adminId,
        ], true);
        (new AssetTaxonomyService())->sync($assetId, [(int) $adventure->id]);
        $locationId = (new LocationModel())->insert([
            'public_id' => '62000000-2222-4333-8444-555555555555', 'name' => 'Surabaya',
            'code' => 'SBY', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $deviceId = (new DeviceModel())->insert([
            'public_id' => '63000000-2222-4333-8444-555555555555', 'name' => 'Studio Surabaya 1',
            'status' => 'active', 'timezone' => 'Asia/Jakarta', 'location' => 'Surabaya',
            'location_id' => $locationId,
        ], true);
        (new DeviceAssetModel())->insert([
            'device_id' => $deviceId, 'asset_id' => $assetId, 'media_key' => 'managed:61000000-2222-4333-8444-555555555555',
            'source' => 'managed', 'title' => 'Mountain Preview', 'filename' => 'mountain-preview.mp4',
            'relative_path' => 'mountain-preview.ldg', 'size_bytes' => 4096, 'duration_ms' => 90000,
            'sha256' => str_repeat('a', 64), 'status' => 'ready', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $library = $this->withSession(['cms_web_user_id' => $adminId])
            ->get('/control/library?type=trailer&genre=' . $adventure->id . '&availability=available');
        $library->assertOK();
        $library->assertSee('Mountain Preview');
        $library->assertSee('TRAILER');
        $library->assertSee('Historical Epic');
        $library->assertSee('1 results');

        $detail = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/library/61000000-2222-4333-8444-555555555555');
        $detail->assertOK();
        $detail->assertSee('Assigned Studios');
        $detail->assertSee('Studio Surabaya 1');
        $detail->assertSee('Surabaya');

        $devices = (new ScheduleService())->readyMediaByDevice();
        $this->assertSame('trailer', $devices[0]['media'][0]['type']);
        $this->assertSame(['Historical Epic'], $devices[0]['media'][0]['genres']);

        $assetsPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/library');
        $assetsPage->assertOK();
        $assetsPage->assertSee('Manage genre options');
        $this->assertStringContainsString('name="genre_ids[]"', $assetsPage->response()->getBody());
        $this->assertStringContainsString('data-genre-multiselect', $assetsPage->response()->getBody());
        $assetsPage->assertSee('Select genres');
        $assetsPage->assertSee('Sci-Fi');
        $assetsPage->assertSee('Asset type');
    }

    public function testMetadataUpdateStoresMultipleGenresAndType(): void
    {
        $adminId = $this->user('taxonomy-admin@example.com', 'Taxonomy Admin', 'admin');
        $taxonomy = new AssetTaxonomyService();
        $action = (new GenreModel())->where('slug', 'action')->first();
        $drama = (new GenreModel())->where('slug', 'drama')->first();
        $this->assertNotNull($action);
        $this->assertNotNull($drama);
        $assetId = (new AssetModel())->insert([
            'public_id' => '64000000-2222-4333-8444-555555555555', 'title' => 'Taxonomy Film',
            'asset_type' => 'featured', 'filename' => 'taxonomy.mp4', 'storage_key' => 'assets/taxonomy.ldg',
            'mime_type' => 'video/mp4', 'size_bytes' => 100, 'sha256' => str_repeat('b', 64),
            'duration_ms' => 1000, 'status' => 'draft', 'created_by' => $adminId,
        ], true);

        $updated = $this->postForm('/control/assets/64000000-2222-4333-8444-555555555555/metadata', [
            'title' => 'Taxonomy Film', 'asset_type' => 'ads', 'genres_present' => '1',
            'genre_ids' => [$action->id, $drama->id], 'synopsis' => '', 'language' => '',
            'subtitles' => '', 'age_rating' => '', 'production_year' => '', 'release_date' => '',
            'expires_on' => '', 'distributor_company' => '',
        ], $adminId);
        $updated->assertRedirectTo('/control/assets');
        $asset = (new AssetModel())->find($assetId);
        $this->assertSame('ads', $asset->asset_type);
        $this->assertSame('Action, Drama', $asset->genre);
        $map = $taxonomy->mapForAssets([$assetId]);
        $this->assertSame(['Action', 'Drama'], array_column($map[$assetId], 'name'));
    }

    private function user(string $email, string $name, string $role): int
    {
        return (new UserModel())->insert([
            'email' => $email, 'name' => $name,
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => $role, 'status' => 'active',
        ], true);
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
