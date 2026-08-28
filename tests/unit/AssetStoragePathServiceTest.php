<?php

use App\Libraries\AssetStoragePathService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class AssetStoragePathServiceTest extends CIUnitTestCase
{
    public function testNewAssetUsesReadableUniqueFolderAndOpaqueAssetFilename(): void
    {
        $service = new AssetStoragePathService();
        $key = $service->newMediaKey(
            'Top Gun: Maverick',
            '0a6a9ff9-613c-4a5b-aee9-6882bbff68ba',
            1,
        );

        $this->assertSame(
            'assets/Top-Gun-Maverick--0a6a9ff9/0a6a9ff9-613c-4a5b-aee9-6882bbff68ba-r1.ldg',
            $key,
        );
    }

    public function testUnsafeNamesAreSanitizedAndDuplicateTitlesRemainUnique(): void
    {
        $service = new AssetStoragePathService();
        $first = $service->newMediaKey('../CON: Trailer?', 'aaaaaaaa-1111-4111-8111-111111111111');
        $second = $service->newMediaKey('../CON: Trailer?', 'bbbbbbbb-1111-4111-8111-111111111111');

        $this->assertSame('assets/CON-Trailer--aaaaaaaa/aaaaaaaa-1111-4111-8111-111111111111-r1.ldg', $first);
        $this->assertSame('assets/CON-Trailer--bbbbbbbb/bbbbbbbb-1111-4111-8111-111111111111-r1.ldg', $second);
        $this->assertStringNotContainsString('..', $first);
    }

    public function testRevisionKeepsNestedFolderAndLegacyAssetsStayFlat(): void
    {
        $service = new AssetStoragePathService();
        $nested = (object) [
            'storage_key' => 'assets/Film Title--12345678/original-r1.ldg',
            'public_id' => '12345678-1234-4234-8234-1234567890ab',
        ];
        $legacy = (object) [
            'storage_key' => 'assets/12345678-r1.ldg',
            'public_id' => '12345678-1234-4234-8234-1234567890ab',
        ];

        $this->assertSame(
            'assets/Film Title--12345678/12345678-1234-4234-8234-1234567890ab-r2.ldg',
            $service->revisionMediaKey($nested, 2),
        );
        $this->assertSame(
            'assets/12345678-1234-4234-8234-1234567890ab-r2.ldg',
            $service->revisionMediaKey($legacy, 2),
        );
    }

    public function testPlayerPathPreservesNestedKeysAndLegacyCacheLayout(): void
    {
        $service = new AssetStoragePathService();
        $nested = (object) [
            'storage_key' => 'assets/Film Title--12345678/original-r1.ldg',
            'public_id' => '12345678-1234-4234-8234-1234567890ab',
            'filename' => 'original.mp4', 'encryption_format' => 'ldg-v1',
        ];
        $legacy = (object) [
            'storage_key' => 'assets/12345678-r1.ldg',
            'public_id' => '12345678-1234-4234-8234-1234567890ab',
            'filename' => 'original.mp4', 'encryption_format' => 'ldg-v1',
        ];

        $this->assertSame('Film Title--12345678/original-r1.ldg', $service->playerRelativePath($nested));
        $this->assertSame('12345678-1234-4234-8234-1234567890ab.ldg', $service->playerRelativePath($legacy));
    }

    public function testAssetDirectoryKeyReturnsOnlyARealChildDirectory(): void
    {
        $service = new AssetStoragePathService();
        $this->assertSame(
            'assets/Film Title--12345678',
            $service->assetDirectoryKey('assets/Film Title--12345678/original-r1.ldg'),
        );
        $this->assertNull($service->assetDirectoryKey('assets/12345678-r1.ldg'));
    }
}
