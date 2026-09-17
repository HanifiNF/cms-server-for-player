<?php

use App\Libraries\ExternalEncryptionJobService;
use App\Models\AssetModel;
use App\Models\ExternalEncryptionJobModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** @internal */
final class ExternalEncryptionJobServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    protected $namespace = 'App';

    public function testClaimProgressAndIdempotentFinalizeCreateSideLoadAsset(): void
    {
        $ownerId = (new UserModel())->insert([
            'email' => 'external-tool@example.com', 'name' => 'External Tool Admin',
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $service = new ExternalEncryptionJobService();
        $job = $service->create($ownerId, ['title' => 'Side-loaded Feature', 'asset_type' => 'featured', 'genre_ids' => []]);
        $claim = $service->claim($job, $ownerId);
        $this->assertSame(32, strlen(base64_decode($claim['key'], true)));
        $authenticated = $service->authenticateJob((string) $job->public_id, 'Bearer ' . $claim['token']);
        $progress = $service->progress($authenticated, ['stage' => 'encrypting', 'processed_bytes' => 50, 'total_bytes' => 100]);
        $this->assertSame('encrypting', $progress->status);
        $plainSize = 1024;
        $outputSize = 128 + $plainSize + 16;
        $payload = [
            'filename' => 'feature.ldg', 'source_filename' => 'feature.mp4', 'mime_type' => 'video/mp4',
            'plaintext_size_bytes' => $plainSize, 'plaintext_sha256' => str_repeat('a', 64),
            'ldg_chunk_size' => ExternalEncryptionJobService::CHUNK_SIZE,
            'size_bytes' => $outputSize, 'sha256' => str_repeat('b', 64), 'duration_ms' => 12345,
        ];
        $asset = $service->finalize($progress, $payload);
        $again = $service->finalize((new ExternalEncryptionJobModel())->find($job->id), $payload);
        $this->assertSame($asset->public_id, $again->public_id);
        $this->assertSame('sideload', $asset->delivery_mode);
        $this->assertNull($asset->storage_key);
        $this->assertSame('active', $asset->status);
        $this->assertSame(1, (new AssetModel())->where('public_id', $asset->public_id)->countAllResults());
    }

    public function testJobTokenCannotAuthenticateAnotherJob(): void
    {
        $ownerId = (new UserModel())->insert([
            'email' => 'external-isolation@example.com', 'name' => 'External Tool Admin',
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $service = new ExternalEncryptionJobService();
        $first = $service->create($ownerId, ['title' => 'First', 'asset_type' => 'featured']);
        $second = $service->create($ownerId, ['title' => 'Second', 'asset_type' => 'featured']);
        $claim = $service->claim($first, $ownerId);
        $this->expectException(RuntimeException::class);
        $service->authenticateJob((string) $second->public_id, 'Bearer ' . $claim['token']);
    }

    public function testPosterIsStagedPublishedAndLinkedWhenJobCompletes(): void
    {
        $ownerId = (new UserModel())->insert([
            'email' => 'external-poster@example.com', 'name' => 'External Poster Admin',
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $temporary = tempnam(sys_get_temp_dir(), 'external-poster-');
        file_put_contents($temporary, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $poster = new class($temporary) {
            public function __construct(private string $path) {}
            public function getTempName(): string { return $this->path; }
        };
        $service = new ExternalEncryptionJobService();
        $job = $service->create($ownerId, ['title' => 'Poster Film', 'asset_type' => 'featured']);
        $job = $service->storePoster($job, $poster, ['filename' => 'poster.png', 'extension' => 'png', 'mime_type' => 'image/png']);
        $staged = (new \App\Libraries\MediaWorkspaceService())->path('upload_staging') . DIRECTORY_SEPARATOR . $job->poster_name;
        $published = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'posters' . DIRECTORY_SEPARATOR . $job->result_public_id . '.png';
        try {
            $this->assertFileExists($staged);
            $plainSize = 1024;
            $asset = $service->finalize($job, [
                'filename' => 'poster-film.ldg', 'source_filename' => 'poster-film.mp4', 'mime_type' => 'video/mp4',
                'plaintext_size_bytes' => $plainSize, 'plaintext_sha256' => str_repeat('c', 64),
                'ldg_chunk_size' => ExternalEncryptionJobService::CHUNK_SIZE,
                'size_bytes' => 128 + $plainSize + 16, 'sha256' => str_repeat('d', 64), 'duration_ms' => 1000,
            ]);
            $this->assertSame('posters/' . $job->result_public_id . '.png', $asset->poster_storage_key);
            $this->assertSame('poster.png', $asset->poster_filename);
            $this->assertSame('image/png', $asset->poster_mime_type);
            $this->assertFileExists($published);
            $this->assertFileDoesNotExist($staged);
        } finally {
            @unlink($temporary);
            @unlink($staged);
            @unlink($published);
        }
    }
}
