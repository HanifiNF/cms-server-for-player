<?php

use App\Libraries\LdgCryptoService;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** @internal */
final class LdgDistributionApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testAssignedPlayerReceivesEncryptedManifestAndLdgDownload(): void
    {
        $token = 'ldg-distribution-player-token';
        $deviceId = (new DeviceModel())->insert([
            'public_id' => '71111111-2222-4333-8444-555555555555',
            'name' => 'Encrypted Cinema Player', 'status' => 'active',
            'timezone' => 'Asia/Jakarta', 'device_key_hash' => hash('sha256', $token),
            'ldg_version' => 'ldg-v1',
        ], true);
        $publicId = '72222222-2222-4333-8444-555555555555';
        $directory = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'assets';
        if (! is_dir($directory)) mkdir($directory, 0775, true);
        $assetDirectoryName = 'Protected-Film--72222222';
        $assetDirectory = $directory . DIRECTORY_SEPARATOR . $assetDirectoryName;
        if (! is_dir($assetDirectory)) mkdir($assetDirectory, 0775, true);
        $source = WRITEPATH . 'ldg-api-source-' . bin2hex(random_bytes(5)) . '.mp4';
        $storedFilename = $publicId . '-r1.ldg';
        $destination = $assetDirectory . DIRECTORY_SEPARATOR . $storedFilename;
        file_put_contents($source, random_bytes(1048576 + 41));

        try {
            $encrypted = (new LdgCryptoService())->encryptFile($source, $destination, $publicId, 1);
            $assetId = (new AssetModel())->insert([
                'public_id' => $publicId, 'revision' => 1, 'title' => 'Protected Film',
                'filename' => 'Protected Film.mp4',
                'storage_key' => 'assets/' . $assetDirectoryName . '/' . $storedFilename,
                'mime_type' => 'video/mp4', ...$encrypted,
                'duration_ms' => 90000, 'status' => 'active',
            ], true);
            (new DeviceAssetModel())->insert([
                'device_id' => $deviceId, 'asset_id' => $assetId,
                'media_key' => 'managed:' . $publicId, 'source' => 'managed',
                'title' => 'Protected Film', 'filename' => 'Protected Film.mp4',
                'relative_path' => 'Protected Film.mp4', 'size_bytes' => $encrypted['size_bytes'],
                'duration_ms' => 90000, 'sha256' => $encrypted['sha256'],
                'status' => 'missing', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $manifest = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/player/assets/assigned');
            $manifest->assertOK();
            $data = json_decode($manifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'][0];
            $this->assertSame('Protected-Film.ldg', $data['filename']);
            $this->assertSame($assetDirectoryName . '/' . $storedFilename, $data['relative_path']);
            $this->assertSame('Protected Film.mp4', $data['display_filename']);
            $this->assertSame('application/vnd.wirgroup.ldg', $data['mime_type']);
            $this->assertSame('ldg-v1', $data['encryption']['format']);
            $this->assertSame(128, $data['encryption']['header_size']);
            $this->assertSame('A256GCM', $data['encryption']['license']['algorithm']);
            $this->assertNotEmpty($data['encryption']['license']['wrapped_key']);

            $legacyToken = 'legacy-player-without-ldg-capability';
            $legacyDeviceId = (new DeviceModel())->insert([
                'public_id' => '73333333-2222-4333-8444-555555555555',
                'name' => 'Legacy Player', 'status' => 'active', 'timezone' => 'Asia/Jakarta',
                'device_key_hash' => hash('sha256', $legacyToken),
            ], true);
            (new DeviceAssetModel())->insert([
                'device_id' => $legacyDeviceId, 'asset_id' => $assetId,
                'media_key' => 'managed:' . $publicId, 'source' => 'managed',
                'title' => 'Protected Film', 'filename' => 'Protected Film.mp4',
                'relative_path' => 'Protected Film.mp4', 'size_bytes' => $encrypted['size_bytes'],
                'duration_ms' => 90000, 'sha256' => $encrypted['sha256'],
                'status' => 'missing', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $legacyManifest = $this->withHeaders(['Authorization' => 'Bearer ' . $legacyToken])->get('/api/player/assets/assigned');
            $legacyManifest->assertOK();
            $this->assertSame([], json_decode($legacyManifest->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']);

            $download = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token, 'Range' => 'bytes=0-127',
            ])->get('/api/player/assets/' . $publicId . '/download');
            $download->assertStatus(206);
            $download->assertHeader('Content-Type', 'application/vnd.wirgroup.ldg');
            $download->assertHeader('Content-Range', 'bytes 0-127/' . $encrypted['size_bytes']);
        } finally {
            if (is_file($source)) unlink($source);
            if (is_file($destination)) unlink($destination);
            if (is_dir($assetDirectory)) rmdir($assetDirectory);
        }
    }
}
