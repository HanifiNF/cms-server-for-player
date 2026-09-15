<?php

namespace App\Libraries;

use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\ExternalEncryptionJobModel;
use App\Models\UserModel;
use Config\Database;
use RuntimeException;
use Throwable;

final class ExternalEncryptionJobService
{
    public const CHUNK_SIZE = 16777216;
    private const LIFETIME = '+7 days';

    public function cleanupExpired(): int
    {
        $model = new ExternalEncryptionJobModel();
        $jobs = $model->whereNotIn('status', ['completed', 'cancelled', 'expired'])
            ->where('expires_at <', gmdate('Y-m-d H:i:s'))->findAll();
        foreach ($jobs as $job) $model->update((int) $job->id, [
            'status' => 'expired', 'job_token_hash' => null, 'wrapped_dek' => null,
            'dek_nonce' => null, 'dek_tag' => null, 'error_message' => 'Encryption job expired after seven days without activity.',
        ]);
        return count($jobs);
    }

    /** @param array<string,mixed> $metadata */
    public function create(int $ownerId, array $metadata): object
    {
        $this->cleanupExpired();
        $publicId = $this->uuidV4();
        $id = (new ExternalEncryptionJobModel())->insert([
            'public_id' => $this->uuidV4(), 'owner_user_id' => $ownerId, 'purpose' => 'asset',
            'result_public_id' => $publicId, 'revision' => 1, 'status' => 'pending',
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime(self::LIFETIME)),
        ], true);
        if (! is_int($id)) throw new RuntimeException('The external encryption job could not be created.');
        return (new ExternalEncryptionJobModel())->find($id);
    }

    /** @return array{job:object,token:string,key:string} */
    public function claim(object $job, int $ownerId): array
    {
        $this->assertOwner($job, $ownerId);
        if (in_array((string) $job->status, ['completed', 'cancelled', 'expired'], true)) throw new RuntimeException('This encryption job is no longer claimable.');
        $crypto = new LdgCryptoService();
        $values = [];
        if (trim((string) ($job->wrapped_dek ?? '')) === '') {
            $key = $crypto->createExternalDataKey((string) $job->result_public_id, (int) $job->revision);
            $values = ['wrapped_dek' => $key['wrapped_dek'], 'dek_nonce' => $key['dek_nonce'], 'dek_tag' => $key['dek_tag'], 'key_version' => 1];
            $plaintextKey = $key['key'];
        } else {
            $plaintextKey = $crypto->recoverExternalDataKey($job);
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $values += ['job_token_hash' => hash('sha256', $token), 'status' => 'claimed', 'error_message' => null, 'expires_at' => gmdate('Y-m-d H:i:s', strtotime(self::LIFETIME))];
        (new ExternalEncryptionJobModel())->update((int) $job->id, $values);
        return ['job' => (new ExternalEncryptionJobModel())->find((int) $job->id), 'token' => $token, 'key' => $plaintextKey];
    }

    public function authenticateJob(string $publicId, string $authorization): object
    {
        $job = (new ExternalEncryptionJobModel())->where('public_id', $publicId)->first();
        if ($job === null || ! preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)
            || ! hash_equals((string) ($job->job_token_hash ?? ''), hash('sha256', trim($matches[1])))) {
            throw new RuntimeException('The encryption job token is invalid.');
        }
        if (strtotime((string) $job->expires_at) < time()) throw new RuntimeException('The encryption job has expired.');
        return $job;
    }

    /** @param array<string,mixed> $payload */
    public function progress(object $job, array $payload): object
    {
        $stage = strtolower(trim((string) ($payload['stage'] ?? '')));
        if (! in_array($stage, ['hashing', 'encrypting', 'verifying', 'registering'], true)) throw new RuntimeException('The progress stage is invalid.');
        $processed = max(0, (int) ($payload['processed_bytes'] ?? 0));
        $total = max(0, (int) ($payload['total_bytes'] ?? 0));
        if ($total > 0 && $processed > $total) throw new RuntimeException('Processed bytes exceed the stage total.');
        (new ExternalEncryptionJobModel())->update((int) $job->id, [
            'status' => $stage, 'progress_stage' => $stage, 'processed_bytes' => $processed, 'total_bytes' => $total,
            'progress_percent' => $total > 0 ? round($processed / $total * 100, 2) : 0,
            'expires_at' => gmdate('Y-m-d H:i:s', strtotime(self::LIFETIME)),
        ]);
        return (new ExternalEncryptionJobModel())->find((int) $job->id);
    }

    /** @param array<string,mixed> $payload */
    public function finalize(object $job, array $payload): object
    {
        if ((string) $job->status === 'completed') return (new AssetModel())->where('public_id', $job->result_public_id)->first();
        $filename = basename(trim((string) ($payload['filename'] ?? '')));
        $plainSize = (int) ($payload['plaintext_size_bytes'] ?? 0);
        $outputSize = (int) ($payload['size_bytes'] ?? 0);
        $plainHash = strtolower(trim((string) ($payload['plaintext_sha256'] ?? '')));
        $outputHash = strtolower(trim((string) ($payload['sha256'] ?? '')));
        $chunkSize = (int) ($payload['ldg_chunk_size'] ?? 0);
        $duration = max(0, (int) ($payload['duration_ms'] ?? 0));
        if (! preg_match('/\.ldg$/i', $filename)) throw new RuntimeException('The package filename must end in .ldg.');
        if ($plainSize <= 0 || $chunkSize !== self::CHUNK_SIZE || ! preg_match('/^[a-f0-9]{64}$/', $plainHash) || ! preg_match('/^[a-f0-9]{64}$/', $outputHash)) {
            throw new RuntimeException('The LDG verification metadata is invalid.');
        }
        $expected = LdgCryptoService::HEADER_SIZE + $plainSize + ((int) ceil($plainSize / $chunkSize) * 16);
        if ($outputSize !== $expected) throw new RuntimeException('The LDG output size does not match its chunk structure.');
        $metadata = json_decode((string) $job->metadata_json, true);
        if (! is_array($metadata)) throw new RuntimeException('The job metadata is unavailable.');
        $owner = (new UserModel())->find((int) $job->owner_user_id);
        if ($owner === null) throw new RuntimeException('The job owner no longer exists.');
        $status = (string) $owner->role === 'admin' ? 'active' : 'draft';
        $now = gmdate('Y-m-d H:i:s');
        $assetValues = [
            'public_id' => (string) $job->result_public_id, 'revision' => (int) $job->revision,
            'title' => (string) ($metadata['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
            'asset_type' => (string) ($metadata['asset_type'] ?? 'featured'), 'synopsis' => $metadata['synopsis'] ?? null,
            'genre' => '', 'language' => $metadata['language'] ?? null, 'subtitles' => $metadata['subtitles'] ?? null,
            'age_rating' => $metadata['age_rating'] ?? null, 'production_year' => $metadata['production_year'] ?? null,
            'release_date' => $metadata['release_date'] ?? null, 'expires_on' => $metadata['expires_on'] ?? null,
            'distributor_company' => $metadata['distributor_company'] ?? null, 'filename' => basename((string) ($payload['source_filename'] ?? $filename)),
            'storage_key' => null, 'storage_profile_id' => null, 'delivery_mode' => 'sideload',
            'mime_type' => (string) ($payload['mime_type'] ?? 'application/octet-stream'), 'size_bytes' => $outputSize,
            'sha256' => $outputHash, 'duration_ms' => $duration, 'status' => $status, 'created_by' => (int) $job->owner_user_id,
            'reviewed_by' => $status === 'active' ? (int) $job->owner_user_id : null, 'reviewed_at' => $status === 'active' ? $now : null,
            'encryption_format' => LdgCryptoService::FORMAT, 'plaintext_size_bytes' => $plainSize, 'plaintext_sha256' => $plainHash,
            'ldg_chunk_size' => $chunkSize, 'wrapped_dek' => $job->wrapped_dek, 'dek_nonce' => $job->dek_nonce,
            'dek_tag' => $job->dek_tag, 'key_version' => 1, 'encryption_revision' => (int) $job->revision,
        ];
        $db = Database::connect();
        $db->transBegin();
        try {
            $assetModel = new AssetModel();
            $assetId = $assetModel->insert($assetValues, true);
            if (! is_int($assetId)) throw new RuntimeException('The side-load asset could not be cataloged.');
            $snapshot = $assetValues; unset($snapshot['wrapped_dek'], $snapshot['dek_nonce'], $snapshot['dek_tag']);
            $version = $assetValues + ['asset_id' => $assetId, 'metadata_snapshot' => json_encode($snapshot), 'submitted_by' => (int) $job->owner_user_id];
            unset($version['public_id'], $version['title'], $version['asset_type'], $version['synopsis'], $version['genre'], $version['language'], $version['subtitles'], $version['age_rating'], $version['production_year'], $version['release_date'], $version['expires_on'], $version['expired_at'], $version['distributor_company'], $version['poster_storage_key'], $version['poster_filename'], $version['poster_mime_type'], $version['created_by']);
            $version['status'] = $status === 'active' ? 'approved' : 'draft';
            if (! is_int((new AssetVersionModel())->insert($version, true))) throw new RuntimeException('The asset revision could not be recorded.');
            (new AssetTaxonomyService())->sync($assetId, array_map('intval', (array) ($metadata['genre_ids'] ?? [])));
            (new ExternalEncryptionJobModel())->update((int) $job->id, [
                'status' => 'completed', 'progress_stage' => 'completed', 'processed_bytes' => $outputSize, 'total_bytes' => $outputSize,
                'progress_percent' => 100, 'filename' => $filename, 'mime_type' => $assetValues['mime_type'],
                'plaintext_size_bytes' => $plainSize, 'plaintext_sha256' => $plainHash, 'ldg_chunk_size' => $chunkSize,
                'output_size_bytes' => $outputSize, 'output_sha256' => $outputHash, 'duration_ms' => $duration,
                'completed_at' => $now,
            ]);
            if ($db->transStatus() === false) throw new RuntimeException('The side-load transaction failed.');
            $db->transCommit();
            return $assetModel->find($assetId);
        } catch (Throwable $error) {
            $db->transRollback();
            throw $error;
        }
    }

    private function assertOwner(object $job, int $ownerId): void
    {
        if ((int) $job->owner_user_id !== $ownerId) throw new RuntimeException('This encryption job belongs to another operator.');
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8), substr($hex,8,4), substr($hex,12,4), substr($hex,16,4), substr($hex,20));
    }
}
