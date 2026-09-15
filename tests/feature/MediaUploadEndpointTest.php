<?php

use App\Libraries\ResumableUploadService;
use App\Models\AssetModel;
use App\Models\MediaUploadSessionModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class MediaUploadEndpointTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    protected function tearDown(): void
    {
        foreach ((new MediaUploadSessionModel())->findAll() as $session) {
            foreach ([$session->staging_name, $session->poster_name] as $name) {
                if (is_string($name) && preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
                    @unlink(WRITEPATH . 'upload-staging' . DIRECTORY_SEPARATOR . $name);
                }
            }
        }
        parent::tearDown();
    }

    public function testInitiateRequiresAuthenticationAndCsrfAndIsOwnerIsolated(): void
    {
        $ownerId = $this->user('upload-endpoint-owner@example.com', 'admin');
        $otherId = $this->user('upload-endpoint-other@example.com', 'admin');
        $values = $this->assetUploadValues();

        $security = config(Security::class);
        $anonymousToken = csrf_hash();
        $anonymous = $this->withHeaders(['Cookie' => $security->cookieName . '=' . $anonymousToken])
            ->post('/control/assets/uploads', [...$values, $security->tokenName => $anonymousToken]);
        $anonymous->assertRedirectTo('/login');

        $tokenResponse = $this->withSession(['cms_web_user_id' => $ownerId])->get('/control/assets/uploads');
        $tokenResponse->assertOK();
        $tokenPayload = json_decode($tokenResponse->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(config(Security::class)->tokenName, $tokenPayload['csrf']['name']);
        $this->assertNotEmpty($tokenPayload['csrf']['hash']);

        $created = $this->postWithCsrf('/control/assets/uploads', $values, $ownerId);
        $created->assertOK();
        $payload = json_decode($created->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('uploading', $payload['data']['session']['status']);
        $this->assertSame(ResumableUploadService::CHUNK_SIZE, $payload['data']['session']['chunk_size_bytes']);
        $this->assertNotEmpty($payload['csrf']['hash']);
        $publicId = $payload['data']['session']['id'];

        $owned = $this->withSession(['cms_web_user_id' => $ownerId])->get('/control/assets/uploads/' . $publicId);
        $owned->assertOK();
        $foreign = $this->withSession(['cms_web_user_id' => $otherId])->get('/control/assets/uploads/' . $publicId);
        $foreign->assertStatus(404);
    }

    public function testInitiateRejectsAMutatingRequestWithoutCsrf(): void
    {
        $ownerId = $this->user('upload-endpoint-csrf@example.com', 'admin');
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        $this->withSession(['cms_web_user_id' => $ownerId])
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/control/assets/uploads', $this->assetUploadValues());
    }

    public function testRevisionSessionRequiresTheRejectedAssetsOwningDistributor(): void
    {
        $ownerId = $this->user('revision-upload-owner@example.com', 'distributor');
        $otherId = $this->user('revision-upload-other@example.com', 'distributor');
        $assetId = (new AssetModel())->insert([
            'public_id' => '91000000-2222-4333-8444-555555555555', 'revision' => 3,
            'title' => 'Rejected Upload', 'asset_type' => 'featured', 'filename' => 'old.mp4',
            'storage_key' => 'assets/old.ldg', 'mime_type' => 'video/mp4', 'size_bytes' => 8,
            'sha256' => str_repeat('c', 64), 'duration_ms' => 1000, 'status' => 'rejected',
            'created_by' => $ownerId,
        ], true);
        $this->assertIsInt($assetId);
        $values = [
            'purpose' => 'revision', 'target_asset_id' => '91000000-2222-4333-8444-555555555555',
            'filename' => 'replacement.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 6,
            'last_modified_ms' => 456, 'fingerprint' => str_repeat('d', 64),
        ];

        $denied = $this->postWithCsrf('/control/assets/uploads', $values, $otherId);
        $denied->assertStatus(422);
        $allowed = $this->postWithCsrf('/control/assets/uploads', $values, $ownerId);
        $allowed->assertOK();
        $session = (new MediaUploadSessionModel())->where('owner_user_id', $ownerId)->first();
        $this->assertNotNull($session);
        $this->assertSame($assetId, (int) $session->target_asset_id);
        $this->assertSame(4, (int) json_decode($session->metadata_json, true, 512, JSON_THROW_ON_ERROR)['_revision']);
        $this->assertSame('91000000-2222-4333-8444-555555555555', $session->result_public_id);
    }

    /** @return array<string,mixed> */
    private function assetUploadValues(): array
    {
        return [
            'purpose' => 'asset', 'title' => 'Large Resumable Film', 'asset_type' => 'featured',
            'filename' => 'large-film.mp4', 'mime_type' => 'video/mp4', 'size_bytes' => 6,
            'last_modified_ms' => 123, 'fingerprint' => str_repeat('a', 64),
        ];
    }

    public function testExternalJobAcceptsMetadataOnlyAndReturnsJson(): void
    {
        $ownerId = $this->user('external-job-web@example.com', 'admin');
        $result = $this->postWithCsrf('/control/assets/external-encryption', [
            'title' => 'Local encryption film', 'asset_type' => 'featured',
            'delivery_mode' => 'sideload',
        ], $ownerId);
        $result->assertOK();
        $payload = json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/control/library', $payload['data']['redirect']);
        $this->assertNotEmpty($payload['csrf']['hash']);
        $job = (new \App\Models\ExternalEncryptionJobModel())->where('public_id', $payload['data']['job_id'])->first();
        $this->assertSame($ownerId, (int) $job->owner_user_id);
        $this->assertSame('pending', $job->status);
    }

    public function testExternalJobValidationReturnsJsonWithoutCreatingJob(): void
    {
        $ownerId = $this->user('external-job-invalid@example.com', 'admin');
        $result = $this->postWithCsrf('/control/assets/external-encryption', [
            'title' => '', 'asset_type' => 'featured',
        ], $ownerId);
        $result->assertStatus(422);
        $payload = json_decode($result->response()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Title is required', $payload['error']['message']);
        $this->assertSame(0, (new \App\Models\ExternalEncryptionJobModel())->where('owner_user_id', $ownerId)->countAllResults());
    }

    /** @param array<string,mixed> $data */
    private function postWithCsrf(string $uri, array $data, int $userId)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withSession(['cms_web_user_id' => $userId])
            ->withHeaders([
                'Cookie' => $security->cookieName . '=' . $token,
                'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest',
            ])->post($uri, [...$data, $security->tokenName => $token]);
    }

    private function user(string $email, string $role): int
    {
        return (new UserModel())->insert([
            'email' => $email, 'name' => 'Upload Endpoint User',
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => $role, 'status' => 'active',
        ], true);
    }
}
