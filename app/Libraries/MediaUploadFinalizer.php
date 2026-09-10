<?php

namespace App\Libraries;

use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\UserModel;
use Config\Database;
use RuntimeException;
use Throwable;

final class MediaUploadFinalizer
{
    /** @return array{public_id:string,message:string,redirect_url:string} */
    public function process(ResumableUploadService $uploads, object $session): array
    {
        $user = (new UserModel())->find((int) $session->owner_user_id);
        if ($user === null || $user->status !== 'active') throw new RuntimeException('The upload owner is no longer an active CMS user.');
        $progress = new MediaProcessingProgress($uploads, $session);
        return $session->purpose === 'revision'
            ? $this->finalizeRevision($uploads, $session, $user, $progress)
            : $this->finalizeAsset($uploads, $session, $user, $progress);
    }

    /** @return array{public_id:string,message:string,redirect_url:string} */
    private function finalizeAsset(ResumableUploadService $uploads, object $session, object $user, MediaProcessingProgress $progress): array
    {
        $metadata = $uploads->metadata($session);
        $genreIds = array_map('intval', (array) ($metadata['_genre_ids'] ?? []));
        unset($metadata['_genre_ids'], $metadata['_poster']);
        $publicId = (string) $session->result_public_id;
        $existing = (new AssetModel())->where('public_id', $publicId)->first();
        if ($existing !== null) return ['public_id' => $publicId, 'message' => 'Asset upload was already finalized.', 'redirect_url' => site_url('control/library/' . $publicId)];

        $storageKey = (new AssetStoragePathService())->newMediaKey((string) $metadata['title'], $publicId, 1);
        $storage = new StorageManager();
        $profile = $storage->defaultProfile();
        $encryptedTemporaryPath = $storage->temporaryPath('.ldg');
        $storedMedia = false;
        $storedPosterKey = null;
        try {
            $sourcePath = $uploads->sourcePath($session);
            $sourceSize = (int) $session->size_bytes;
            $progress->report('probing', 0, 1, true);
            $durationMs = (new MediaMetadataService())->detectDurationMs($sourcePath);
            $progress->report('probing', 1, 1, true);
            $encryptionValues = (new LdgCryptoService())->encryptFile(
                $sourcePath,
                $encryptedTemporaryPath,
                $publicId,
                1,
                static fn (string $stage, int $bytes, int $total) => $progress->report($stage, $bytes, $total),
            );
            $encryptedSize = (int) ($encryptionValues['size_bytes'] ?? $sourceSize);
            $progress->report('storing', 0, $encryptedSize, true);
            $storage->putFile($profile, $encryptedTemporaryPath, $storageKey, static fn (int $bytes, int $total) => $progress->report('storing', $bytes, $total));
            $storedMedia = true;

            $posterValues = ['poster_storage_key' => null, 'poster_filename' => null, 'poster_mime_type' => null];
            $posterPath = $uploads->posterPath($session);
            $posterInfo = (array) (($uploads->metadata($session))['_poster'] ?? []);
            if ($posterPath !== null && is_file($posterPath) && $posterInfo !== []) {
                $storedPosterKey = 'posters/' . $publicId . '.' . $posterInfo['extension'];
                $storage->putFile($profile, $posterPath, $storedPosterKey);
                $posterValues = ['poster_storage_key' => $storedPosterKey, 'poster_filename' => $posterInfo['filename'], 'poster_mime_type' => $posterInfo['mime_type']];
            }

            $progress->report('cataloging', 0, 1, true);
            $status = $user->role === 'distributor' ? 'draft' : 'active';
            $assetValues = [
                'public_id' => $publicId, 'revision' => 1, ...$metadata, ...$posterValues,
                'filename' => $session->filename, 'storage_key' => $storageKey,
                'storage_profile_id' => (int) $profile->id, 'mime_type' => $session->mime_type,
                ...$encryptionValues, 'duration_ms' => $durationMs, 'status' => $status,
                'created_by' => (int) $user->id,
            ];
            $db = Database::connect();
            $db->transBegin();
            $assetId = (new AssetModel())->insert($assetValues, true);
            if (! is_int($assetId)) throw new RuntimeException('Asset metadata could not be stored.');
            if ($genreIds !== []) (new AssetTaxonomyService($db))->sync($assetId, $genreIds);
            $versionId = (new AssetVersionModel())->insert([
                'asset_id' => $assetId, 'revision' => 1,
                'filename' => $session->filename, 'storage_key' => $storageKey,
                'storage_profile_id' => (int) $profile->id, 'mime_type' => $session->mime_type,
                ...$encryptionValues, 'duration_ms' => $durationMs,
                'status' => $status === 'active' ? 'approved' : 'draft',
                'metadata_snapshot' => $this->versionMetadataSnapshot($assetValues),
                'submitted_by' => (int) $user->id,
                'reviewed_by' => $status === 'active' ? (int) $user->id : null,
                'reviewed_at' => $status === 'active' ? gmdate('Y-m-d H:i:s') : null,
            ], true);
            if (! is_int($versionId) || $db->transStatus() === false) throw new RuntimeException('Asset revision could not be stored.');
            $db->transCommit();
            $progress->report('cataloging', 1, 1, true);
            $message = $status === 'draft' ? 'Film uploaded as Draft for administrator approval.' : 'Asset uploaded, encrypted, and added to the catalog.';
            return ['public_id' => $publicId, 'message' => $message, 'redirect_url' => site_url('control/library/' . $publicId)];
        } catch (Throwable $error) {
            if (isset($db)) $db->transRollback();
            if ($storedMedia) try { $storage->delete($profile, $storageKey); } catch (Throwable) {}
            if ($storedPosterKey !== null) try { $storage->delete($profile, $storedPosterKey); } catch (Throwable) {}
            throw $error;
        } finally {
            if (is_file($encryptedTemporaryPath)) @unlink($encryptedTemporaryPath);
        }
    }

    /** @return array{public_id:string,message:string,redirect_url:string} */
    private function finalizeRevision(ResumableUploadService $uploads, object $session, object $user, MediaProcessingProgress $progress): array
    {
        $asset = (new AssetModel())->find((int) $session->target_asset_id);
        $metadata = $uploads->metadata($session);
        $revision = max(2, (int) ($metadata['_revision'] ?? 0));
        $existingVersion = $asset === null ? null : (new AssetVersionModel())->where('asset_id', $asset->id)->where('revision', $revision)->first();
        if ($asset !== null && $existingVersion !== null) return ['public_id' => (string) $asset->public_id, 'message' => "Revision {$revision} was already finalized.", 'redirect_url' => site_url('control/library/' . $asset->public_id)];
        if ($asset === null || $user->role !== 'distributor' || (int) $asset->created_by !== (int) $user->id || $asset->status !== 'rejected') {
            throw new RuntimeException('Only the owning distributor can replace media for a rejected film.');
        }

        $storage = new StorageManager();
        $profile = $storage->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
        $storageKey = (new AssetStoragePathService())->revisionMediaKey($asset, $revision);
        $temporaryPath = $storage->temporaryPath('.ldg');
        $stored = false;
        try {
            $sourcePath = $uploads->sourcePath($session);
            $progress->report('probing', 0, 1, true);
            $durationMs = (new MediaMetadataService())->detectDurationMs($sourcePath);
            $progress->report('probing', 1, 1, true);
            $encryptionValues = (new LdgCryptoService())->encryptFile(
                $sourcePath,
                $temporaryPath,
                (string) $asset->public_id,
                $revision,
                static fn (string $stage, int $bytes, int $total) => $progress->report($stage, $bytes, $total),
            );
            $encryptedSize = (int) ($encryptionValues['size_bytes'] ?? $session->size_bytes);
            $progress->report('storing', 0, $encryptedSize, true);
            $storage->putFile($profile, $temporaryPath, $storageKey, static fn (int $bytes, int $total) => $progress->report('storing', $bytes, $total));
            $stored = true;
            $progress->report('cataloging', 0, 1, true);
            $fileValues = [
                'filename' => $session->filename, 'storage_key' => $storageKey,
                'storage_profile_id' => (int) $profile->id, 'mime_type' => $session->mime_type,
                ...$encryptionValues, 'duration_ms' => $durationMs,
            ];
            $db = Database::connect();
            $db->transBegin();
            if (! (new AssetModel())->update($asset->id, [...$fileValues, 'revision' => $revision, 'status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null])) {
                throw new RuntimeException('Corrected asset could not be saved.');
            }
            $versionId = (new AssetVersionModel())->insert([
                'asset_id' => $asset->id, 'revision' => $revision, ...$fileValues,
                'status' => 'draft', 'metadata_snapshot' => $this->versionMetadataSnapshot($asset),
                'submitted_by' => (int) $user->id,
            ], true);
            if (! is_int($versionId) || $db->transStatus() === false) throw new RuntimeException('Corrected revision could not be saved.');
            $db->transCommit();
            $progress->report('cataloging', 1, 1, true);
            return ['public_id' => (string) $asset->public_id, 'message' => "Revision {$revision} submitted as Draft for administrator review.", 'redirect_url' => site_url('control/library/' . $asset->public_id)];
        } catch (Throwable $error) {
            if (isset($db)) $db->transRollback();
            if ($stored) try { $storage->delete($profile, $storageKey); } catch (Throwable) {}
            throw $error;
        } finally {
            if (is_file($temporaryPath)) @unlink($temporaryPath);
        }
    }

    /** @param array<string,mixed>|object $asset */
    private function versionMetadataSnapshot(array|object $asset): string
    {
        $snapshot = [];
        foreach (['title', 'asset_type', 'synopsis', 'genre', 'language', 'subtitles', 'age_rating', 'production_year', 'release_date', 'expires_on', 'distributor_company'] as $field) {
            $value = is_array($asset) ? ($asset[$field] ?? null) : ($asset->{$field} ?? null);
            if (is_object($value) && method_exists($value, 'format')) $value = $value->format('Y-m-d');
            $snapshot[$field] = $value;
        }
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
