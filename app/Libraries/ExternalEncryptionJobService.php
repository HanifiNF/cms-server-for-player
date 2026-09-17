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
        foreach ($jobs as $job) {
            $this->removeStagedPoster($job);
            $model->update((int) $job->id, [
                'status' => 'expired', 'poster_name' => null, 'job_token_hash' => null, 'wrapped_dek' => null,
                'dek_nonce' => null, 'dek_tag' => null, 'error_message' => 'Encryption job expired after seven days without activity.',
            ]);
        }
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

    /** @param array<string,string> $posterInfo */
    public function storePoster(object $job, object $poster, array $posterInfo): object
    {
        $source = (string) $poster->getTempName();
        if ($source === '' || ! is_file($source)) throw new RuntimeException('Poster staging file was not found.');
        $name = (string) $job->public_id . '.' . bin2hex(random_bytes(8)) . '.poster.' . (string) $posterInfo['extension'];
        $destination = $this->posterPath($name);
        if (! @copy($source, $destination)) throw new RuntimeException('Poster could not be staged for the encryption job.');
        $metadata = json_decode((string) $job->metadata_json, true);
        if (! is_array($metadata)) $metadata = [];
        $metadata['_poster'] = $posterInfo;
        $updated = (new ExternalEncryptionJobModel())->update((int) $job->id, [
            'poster_name' => $name,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
        if (! $updated) { @unlink($destination); throw new RuntimeException('Poster metadata could not be saved.'); }
        return (new ExternalEncryptionJobModel())->find((int) $job->id);
    }

    public function cancel(object $job): void
    {
        if ((string) $job->status === 'completed') throw new RuntimeException('A completed job cannot be cancelled.');
        $this->removeStagedPoster($job);
        (new ExternalEncryptionJobModel())->update((int) $job->id, [
            'status' => 'cancelled', 'poster_name' => null, 'job_token_hash' => null,
        ]);
    }

    public function discard(object $job): void
    {
        $this->removeStagedPoster($job);
        (new ExternalEncryptionJobModel())->delete((int) $job->id);
    }

    public function saveMetadata(int $ownerId, array $input, ?object $poster, ?string $publicId = null): object
    {
        $validator = new ExternalJobMetadata();
        $metadata = $validator->validate($input);
        $posterInfo = $validator->poster($poster);
        $key = (string) ($input['creation_key'] ?? '');
        if ($publicId === null && ! preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key)) throw new RuntimeException('A creation key is required.');
        $db = Database::connect(); $db->transBegin(); $old = null; $saved = null;
        try {
            // Serialize creates for this operator so lost-response retries return
            // the original job, including its staged poster.
            if ($db->DBDriver === 'Postgre') $db->query('SELECT pg_advisory_xact_lock(19471, ?)', [$ownerId]);
            $model = new ExternalEncryptionJobModel();
            if ($publicId === null) {
                $existing = $model->where('owner_user_id', $ownerId)->where('creation_key', $key)->first();
                if ($existing) { $db->transCommit(); return $existing; }
                $saved = $this->create($ownerId, $metadata);
                $model->update($saved->id, ['creation_key'=>$key]);
            } else {
                $old = $model->where('public_id', $publicId)->where('owner_user_id', $ownerId)->first();
                if (! $old) throw new RuntimeException('Encryption job was not found.');
                if ($old->status !== 'pending' || (int) $old->edit_version !== (int) ($input['edit_version'] ?? 0)
                    || strtotime($old->expires_at) < time()) throw new RuntimeException('Job changed or was claimed. Reload it before continuing.', 409);
                $oldMetadata = json_decode($old->metadata_json, true);
                $remove = ($input['remove_poster'] ?? '') === '1';
                if (! $remove && isset($oldMetadata['_poster'])) $metadata['_poster'] = $oldMetadata['_poster'];
                $db->table('external_encryption_jobs')->where('id', $old->id)->where('status','pending')->where('edit_version', $old->edit_version)->update([
                    'metadata_json'=>json_encode($metadata), 'edit_version'=>(int) $old->edit_version + 1,
                    'poster_name'=>$remove ? null : $old->poster_name, 'expires_at'=>gmdate('Y-m-d H:i:s', strtotime(self::LIFETIME)),
                ]);
                if ($db->affectedRows() !== 1) throw new RuntimeException('Job changed or was claimed. Reload it before continuing.', 409);
                $saved = $model->find($old->id);
            }
            if ($posterInfo) $saved = $this->storePoster($saved, $poster, $posterInfo);
            $saved = $model->find($saved->id);
            if (! $db->transStatus()) throw new RuntimeException('Metadata could not be saved.');
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();
            if ($saved && $saved->poster_name && $saved->poster_name !== ($old->poster_name ?? null)) $this->removeStagedPoster($saved);
            throw $e;
        }
        if ($old && $old->poster_name !== $saved->poster_name) $this->removeStagedPoster($old);
        return $saved;
    }

    public function stagedPoster(object $job): ?string
    {
        return $job->poster_name ? $this->posterPath($job->poster_name, false) : null;
    }

    /** @return array{job:object,token:string,key:string} */
    public function claim(object $job, int $ownerId, ?int $expectedVersion = null): array
    {
        $this->assertOwner($job, $ownerId);
        if ($expectedVersion !== null && ((int) $job->edit_version !== $expectedVersion || $job->status !== 'pending')) throw new RuntimeException('Job changed or was claimed. Reload it before continuing.', 409);
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
        $values['edit_version'] = (int) $job->edit_version + 1;
        $db = Database::connect();
        $db->table('external_encryption_jobs')->where('id', $job->id)->where('edit_version', $job->edit_version)->where('status', $job->status)->update($values);
        if ($db->affectedRows() !== 1) throw new RuntimeException('Job changed or was claimed. Reload it before continuing.', 409);
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
        Database::connect()->table('external_encryption_jobs')->where('id', $job->id)->whereNotIn('status', ['completed','cancelled','expired'])->update([
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
        $posterValues = ['poster_storage_key' => null, 'poster_filename' => null, 'poster_mime_type' => null];
        $posterProfileId = null;
        $posterInfo = (array) ($metadata['_poster'] ?? []);
        $posterPath = $job->poster_name ? $this->posterPath((string) $job->poster_name, false) : null;
        if ($posterPath !== null && is_file($posterPath) && $posterInfo !== []) {
            $posterKey = 'posters/' . (string) $job->result_public_id . '.' . (string) $posterInfo['extension'];
            $storage = new StorageManager();
            $profile = $storage->defaultProfile();
            $storage->putFile($profile, $posterPath, $posterKey);
            $posterProfileId = (int) $profile->id;
            $posterValues = [
                'poster_storage_key' => $posterKey,
                'poster_filename' => (string) $posterInfo['filename'],
                'poster_mime_type' => (string) $posterInfo['mime_type'],
            ];
        } elseif ($posterInfo !== []) {
            log_message('warning', 'External encryption poster staging file is missing for job {job}.', ['job' => $job->public_id]);
        }
        $assetValues = [
            'public_id' => (string) $job->result_public_id, 'revision' => (int) $job->revision,
            'title' => (string) ($metadata['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
            'asset_type' => (string) ($metadata['asset_type'] ?? 'featured'), 'synopsis' => $metadata['synopsis'] ?? null,
            'genre' => '', 'language' => $metadata['language'] ?? null, 'subtitles' => $metadata['subtitles'] ?? null,
            'age_rating' => $metadata['age_rating'] ?? null, 'production_year' => $metadata['production_year'] ?? null,
            'release_date' => $metadata['release_date'] ?? null, 'expires_on' => $metadata['expires_on'] ?? null,
            'distributor_company' => $metadata['distributor_company'] ?? null, ...$posterValues,
            'filename' => basename((string) ($payload['source_filename'] ?? $filename)),
            'storage_key' => null, 'storage_profile_id' => $posterProfileId, 'delivery_mode' => 'sideload',
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
            if ($posterPath !== null && is_file($posterPath)) {
                if (@unlink($posterPath)) (new ExternalEncryptionJobModel())->update((int) $job->id, ['poster_name' => null]);
                else log_message('warning', 'Completed encryption job poster staging file could not be deleted: {path}', ['path' => $posterPath]);
            }
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

    private function removeStagedPoster(object $job): void
    {
        if (! isset($job->poster_name) || ! is_string($job->poster_name) || $job->poster_name === '') return;
        try {
            $path = $this->posterPath($job->poster_name, false);
            if (is_file($path) && ! @unlink($path)) log_message('warning', 'Encryption job poster staging file could not be deleted: {path}', ['path' => $path]);
        } catch (Throwable $error) {
            log_message('warning', 'Encryption job poster cleanup failed: {message}', ['message' => $error->getMessage()]);
        }
    }

    private function posterPath(string $name, bool $createDirectory = true): string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) throw new RuntimeException('Poster staging filename is invalid.');
        $directory = (new MediaWorkspaceService())->path('upload_staging');
        if ($createDirectory && ! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Poster staging directory could not be created.');
        }
        return rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . $name;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8), substr($hex,8,4), substr($hex,12,4), substr($hex,16,4), substr($hex,20));
    }
}
