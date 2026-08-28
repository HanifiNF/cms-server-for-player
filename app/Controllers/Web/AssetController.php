<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\UserModel;
use App\Models\LocationModel;
use App\Libraries\AssetExpiryService;
use App\Libraries\AssetStoragePathService;
use App\Libraries\AssetTaxonomyService;
use App\Models\GenreModel;
use App\Libraries\MediaMetadataService;
use App\Libraries\LdgCryptoService;
use App\Libraries\RealtimeOutboxService;
use App\Libraries\StorageManager;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;
use Throwable;

class AssetController extends BaseController
{
    private const EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'];
    private const POSTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const AGE_RATINGS = ['SU', '13+', '17+', '21+'];
    private const POSTER_MAX_BYTES = 10485760;

    public function index(): RedirectResponse
    {
        session()->keepFlashdata(['success', 'error', 'errors', 'modal']);
        $query = trim((string) $this->request->getServer('QUERY_STRING'));
        return redirect()->to('/control/library' . ($query !== '' ? '?' . $query : ''));
    }

    public function upload(): ResponseInterface
    {
        $file = $this->request->getFile('media');
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return $this->uploadFailure('Choose a media file. Check PHP upload_max_filesize and post_max_size when uploading large films.');
        }
        $filename = basename($file->getClientName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true)) return $this->uploadFailure('The selected file type is not supported.');
        try {
            $taxonomyInput = $this->taxonomyInput();
        } catch (RuntimeException $error) {
            return $this->uploadFailure($error->getMessage());
        }
        $metadata = $this->metadataInput(pathinfo($filename, PATHINFO_FILENAME));
        if ($taxonomyInput['ids'] !== null) $metadata['genre'] = $this->legacyGenreSummary($taxonomyInput['names']);
        $metadataErrors = $this->metadataErrors($metadata);
        if ($metadataErrors !== []) return $this->uploadFailure(reset($metadataErrors));
        if (mb_strlen($filename) > 255) return $this->uploadFailure('The filename is too long.');
        $poster = $this->request->getFile('poster');
        $posterInfo = $this->posterInfo($poster);
        if (isset($posterInfo['error'])) return $this->uploadFailure($posterInfo['error']);

        $publicId = $this->uuidV4();
        $storageKey = (new AssetStoragePathService())->newMediaKey($metadata['title'], $publicId, 1);
        $storage = new StorageManager();
        $profile = null;
        $encryptedTemporaryPath = null;
        $storedMedia = false;
        $storedPosterKey = null;
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        try {
            $profile = $storage->defaultProfile();
            $sourcePath = $file->getTempName();
            if ($sourcePath === '' || ! is_file($sourcePath)) throw new RuntimeException('Uploaded media temporary file was not found.');
            $durationMs = (new MediaMetadataService())->detectDurationMs($sourcePath);
            $encryptedTemporaryPath = $storage->temporaryPath('.ldg');
            $encryptionValues = (new LdgCryptoService())->encryptFile($sourcePath, $encryptedTemporaryPath, $publicId, 1);
            $storage->putFile($profile, $encryptedTemporaryPath, $storageKey);
            $storedMedia = true;
            $posterValues = ['poster_storage_key' => null, 'poster_filename' => null, 'poster_mime_type' => null];
            if ($posterInfo !== []) {
                $posterStoredName = $publicId . '.' . $posterInfo['extension'];
                $posterSource = $poster->getTempName();
                if ($posterSource === '' || ! is_file($posterSource)) throw new RuntimeException('Uploaded poster temporary file was not found.');
                $storedPosterKey = 'posters/' . $posterStoredName;
                $storage->putFile($profile, $posterSource, $storedPosterKey);
                $posterValues = [
                    'poster_storage_key' => $storedPosterKey,
                    'poster_filename' => $posterInfo['filename'],
                    'poster_mime_type' => $posterInfo['mime_type'],
                ];
            }
            $currentUser = $this->currentUser();
            $status = $currentUser->role === 'distributor' ? 'draft' : 'active';
            $assetValues = [
                'public_id' => $publicId, 'revision' => 1, ...$metadata, ...$posterValues, 'filename' => $filename,
                'storage_key' => $storageKey, 'storage_profile_id' => (int) $profile->id, 'mime_type' => $mimeType,
                ...$encryptionValues,
                'duration_ms' => $durationMs, 'status' => $status,
                'created_by' => (int) $currentUser->id,
            ];
            $db = Database::connect();
            $db->transBegin();
            $inserted = (new AssetModel())->insert($assetValues, true);
            if (! is_int($inserted)) throw new \RuntimeException('Asset metadata could not be stored.');
            if ($taxonomyInput['ids'] !== null) (new AssetTaxonomyService($db))->sync($inserted, $taxonomyInput['ids']);
            $versionInserted = (new AssetVersionModel())->insert([
                'asset_id' => $inserted, 'revision' => 1,
                'filename' => $filename, 'storage_key' => $storageKey, 'storage_profile_id' => (int) $profile->id,
                'mime_type' => $mimeType, ...$encryptionValues,
                'duration_ms' => $durationMs, 'status' => $status === 'active' ? 'approved' : 'draft',
                'metadata_snapshot' => $this->versionMetadataSnapshot($assetValues),
                'submitted_by' => (int) $currentUser->id,
                'reviewed_by' => $status === 'active' ? (int) $currentUser->id : null,
                'reviewed_at' => $status === 'active' ? gmdate('Y-m-d H:i:s') : null,
            ], true);
            if (! is_int($versionInserted) || $db->transStatus() === false) throw new RuntimeException('Asset revision could not be stored.');
            $db->transCommit();
        } catch (Throwable $exception) {
            if (isset($db)) $db->transRollback();
            if ($profile !== null && $storedMedia) try { $storage->delete($profile, $storageKey); } catch (Throwable) {}
            if ($profile !== null && $storedPosterKey !== null) try { $storage->delete($profile, $storedPosterKey); } catch (Throwable) {}
            log_message('error', 'Asset upload failed: {message}', ['message' => $exception->getMessage()]);
            return $this->uploadFailure('The media asset could not be uploaded.', 500);
        } finally {
            if ($encryptedTemporaryPath !== null && is_file($encryptedTemporaryPath)) @unlink($encryptedTemporaryPath);
        }
        $message = $status === 'draft'
            ? 'Film encrypted as LDG v1, uploaded as Draft, and is waiting for administrator approval.'
            : ($durationMs > 0
                ? 'Asset encrypted as LDG v1 with automatically detected duration. Assign it to a Player to start remote download.'
                : 'Asset encrypted as LDG v1. Duration is pending.');
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(201)->setJSON([
                'data' => ['asset_id' => $publicId, 'message' => $message],
            ]);
        }
        return redirect()->to('/control/assets')->with('success', $message);
    }

    public function updateMetadata(string $publicId): RedirectResponse
    {
        $asset = $this->accessibleAsset($publicId);
        if ($asset === null) return redirect()->to('/control/assets')->with('error', 'Asset was not found.');
        $currentUser = $this->currentUser();
        if ($currentUser->role === 'distributor' && ! in_array($asset->status, ['draft', 'rejected'], true)) {
            return redirect()->to('/control/assets')->with('error', 'Approved film metadata can only be changed by an administrator.');
        }
        try {
            $taxonomyInput = $this->taxonomyInput();
        } catch (RuntimeException $error) {
            return redirect()->to('/control/assets')->with('error', $error->getMessage());
        }
        $metadata = $this->metadataInput((string) $asset->title);
        if ($taxonomyInput['ids'] !== null) $metadata['genre'] = $this->legacyGenreSummary($taxonomyInput['names']);
        $errors = $this->metadataErrors($metadata, $asset->status === 'expired');
        if ($errors !== []) return redirect()->to('/control/assets')->with('errors', $errors);
        $poster = $this->request->getFile('poster');
        $posterInfo = $this->posterInfo($poster);
        if (isset($posterInfo['error'])) return redirect()->to('/control/assets')->with('error', $posterInfo['error']);

        $storage = new StorageManager();
        $profile = $storage->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
        $newPosterKey = null;
        $oldPosterKey = (string) $asset->poster_storage_key;
        try {
            if ($posterInfo !== []) {
                $storedName = $asset->public_id . '-' . bin2hex(random_bytes(6)) . '.' . $posterInfo['extension'];
                $posterSource = $poster->getTempName();
                if ($posterSource === '' || ! is_file($posterSource)) throw new RuntimeException('Uploaded poster temporary file was not found.');
                $newPosterKey = 'posters/' . $storedName;
                $storage->putFile($profile, $posterSource, $newPosterKey);
                $metadata['poster_storage_key'] = $newPosterKey;
                $metadata['poster_filename'] = $posterInfo['filename'];
                $metadata['poster_mime_type'] = $posterInfo['mime_type'];
            }
            $db = Database::connect();
            $db->transBegin();
            if (! (new AssetModel())->update($asset->id, $metadata)) throw new RuntimeException('Metadata could not be saved.');
            if ($taxonomyInput['ids'] !== null) (new AssetTaxonomyService($db))->sync((int) $asset->id, $taxonomyInput['ids']);
            if ($asset->status === 'draft') {
                $version = $this->currentVersionOrCreate($asset);
                if (! (new AssetVersionModel())->update($version->id, [
                    'metadata_snapshot' => $this->versionMetadataSnapshot($metadata),
                ])) throw new RuntimeException('Draft revision metadata could not be saved.');
            }
            Database::connect()->table('device_assets')->where('asset_id', $asset->id)->update(['title' => $metadata['title']]);
            if ($db->transStatus() === false) throw new RuntimeException('Metadata transaction failed.');
            $db->transCommit();
        } catch (Throwable $exception) {
            if (isset($db)) $db->transRollback();
            if ($newPosterKey !== null) try { $storage->delete($profile, $newPosterKey); } catch (Throwable) {}
            log_message('error', 'Asset metadata update failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/assets')->with('error', 'Film metadata could not be updated.');
        }
        if ($newPosterKey !== null && $oldPosterKey !== '') try { $storage->delete($profile, $oldPosterKey); } catch (Throwable $error) {
            log_message('error', 'Old asset poster cleanup failed: {message}', ['message' => $error->getMessage()]);
        }
        return redirect()->to('/control/assets')->with('success', 'Film metadata updated.');
    }

    public function resubmit(string $publicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $asset = $this->accessibleAsset($publicId);
        $currentUser = $this->currentUser();
        if ($asset === null || $currentUser->role !== 'distributor' || $asset->status !== 'rejected') {
            return redirect()->to('/control/assets')->with('error', 'Only the owning distributor can resubmit a rejected film.');
        }

        $file = $this->request->getFile('media');
        $hasReplacement = $file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE;
        $revision = max(1, (int) $asset->revision) + 1;
        $storage = new StorageManager();
        $profile = $storage->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
        $newStoredKey = null;
        $newTemporaryPath = null;
        $fileValues = [
            'filename' => $asset->filename, 'storage_key' => $asset->storage_key,
            'storage_profile_id' => (int) $profile->id,
            'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes,
            'sha256' => $asset->sha256, 'duration_ms' => $asset->duration_ms,
            'encryption_format' => $asset->encryption_format,
            'plaintext_size_bytes' => $asset->plaintext_size_bytes,
            'plaintext_sha256' => $asset->plaintext_sha256,
            'ldg_chunk_size' => $asset->ldg_chunk_size,
            'wrapped_dek' => $asset->wrapped_dek,
            'dek_nonce' => $asset->dek_nonce,
            'dek_tag' => $asset->dek_tag,
            'key_version' => $asset->key_version,
            'encryption_revision' => $asset->encryption_revision,
        ];

        try {
            if ($hasReplacement) {
                if (! $file->isValid() || $file->hasMoved()) throw new RuntimeException('Choose a valid replacement media file.');
                $filename = basename($file->getClientName());
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (! in_array($extension, self::EXTENSIONS, true)) throw new RuntimeException('The replacement file type is not supported.');
                if (mb_strlen($filename) > 255) throw new RuntimeException('The replacement filename is too long.');
                $newStoredKey = (new AssetStoragePathService())->revisionMediaKey($asset, $revision);
                $newTemporaryPath = $storage->temporaryPath('.ldg');
                $sourcePath = $file->getTempName();
                if ($sourcePath === '' || ! is_file($sourcePath)) throw new RuntimeException('Replacement media temporary file was not found.');
                $encryptionValues = (new LdgCryptoService())->encryptFile($sourcePath, $newTemporaryPath, (string) $asset->public_id, $revision);
                $storage->putFile($profile, $newTemporaryPath, $newStoredKey);
                $fileValues = [
                    'filename' => $filename, 'storage_key' => $newStoredKey, 'storage_profile_id' => (int) $profile->id,
                    'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
                    ...$encryptionValues,
                    'duration_ms' => (new MediaMetadataService())->detectDurationMs($sourcePath),
                ];
            }

            $db = Database::connect();
            $db->transBegin();
            $updated = (new AssetModel())->update($asset->id, [
                ...$fileValues, 'revision' => $revision, 'status' => 'draft',
                'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
            ]);
            if (! $updated) throw new RuntimeException('Corrected asset could not be saved.');
            $versionId = (new AssetVersionModel())->insert([
                'asset_id' => $asset->id, 'revision' => $revision, ...$fileValues,
                'status' => 'draft', 'metadata_snapshot' => $this->versionMetadataSnapshot($asset),
                'submitted_by' => (int) $currentUser->id,
            ], true);
            if (! is_int($versionId) || $db->transStatus() === false) throw new RuntimeException('Corrected revision could not be saved.');
            $db->transCommit();
        } catch (Throwable $error) {
            if (isset($db)) $db->transRollback();
            if ($newStoredKey !== null) try { $storage->delete($profile, $newStoredKey); } catch (Throwable) {}
            log_message('error', 'Asset resubmission failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->to('/control/assets')->with('error', $error->getMessage());
        } finally {
            if ($newTemporaryPath !== null && is_file($newTemporaryPath)) @unlink($newTemporaryPath);
        }

        return redirect()->to('/control/assets')->with('success', "Revision {$revision} submitted as Draft for administrator review.");
    }

    public function poster(string $publicId): ResponseInterface
    {
        $asset = $this->accessibleAsset($publicId);
        if ($asset === null || $asset->poster_storage_key === null) return $this->response->setStatusCode(404);
        $profile = (new StorageManager())->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
        $path = (new StorageManager())->materialize($profile, (string) $asset->poster_storage_key);
        if ($path === null) return $this->response->setStatusCode(404);
        return $this->response->download($path, null)
            ->setFileName((string) ($asset->poster_filename ?: basename($path)))
            ->inline()
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    public function createGenre(): RedirectResponse
    {
        try {
            (new AssetTaxonomyService())->createGenre((string) $this->request->getPost('name'), (int) session()->get('cms_web_user_id'));
            return redirect()->to('/control/assets')->with('success', 'Genre created and is now available in asset forms.')->with('modal', 'genre-manager-modal');
        } catch (RuntimeException $error) {
            return redirect()->to('/control/assets')->with('error', $error->getMessage())->with('modal', 'genre-manager-modal');
        }
    }

    public function genreStatus(string $publicId): RedirectResponse
    {
        $genre = (new GenreModel())->where('public_id', $publicId)->first();
        if ($genre === null) return redirect()->to('/control/assets')->with('error', 'Genre was not found.')->with('modal', 'genre-manager-modal');
        $status = (string) $this->request->getPost('status');
        if (! in_array($status, ['active', 'inactive'], true)) return redirect()->to('/control/assets')->with('error', 'Genre status is invalid.')->with('modal', 'genre-manager-modal');
        if (! (new GenreModel())->update($genre->id, ['status' => $status])) return redirect()->to('/control/assets')->with('error', 'Genre status could not be updated.')->with('modal', 'genre-manager-modal');
        return redirect()->to('/control/assets')->with('success', 'Genre status updated. Existing film metadata is preserved.')->with('modal', 'genre-manager-modal');
    }

    public function assign(string $publicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $asset = (new AssetModel())->where('public_id', $publicId)->where('status', 'active')->first();
        $device = (new DeviceModel())->where('public_id', trim((string) $this->request->getPost('device_id')))->where('status', 'active')->first();
        if ($device !== null && $device->location_id !== null) {
            $location = (new LocationModel())->find((int) $device->location_id);
            if ($location === null || $location->status !== 'active') $device = null;
        }
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Choose an active asset and Studio.');
        if ($asset->encryption_format === LdgCryptoService::FORMAT && $device->ldg_version !== LdgCryptoService::FORMAT) {
            return redirect()->to('/control/assets')->with('error', 'This Player must be updated and report LDG v1 support before encrypted films can be assigned.');
        }
        $model = new DeviceAssetModel();
        $db = Database::connect();
        $mediaKey = 'managed:' . $asset->public_id;
        $existing = $model->where('device_id', $device->id)->where('media_key', $mediaKey)->first();
        $values = [
            'device_id' => $device->id, 'asset_id' => $asset->id, 'media_key' => $mediaKey,
            'source' => 'managed', 'title' => $asset->title, 'filename' => $asset->filename,
            'relative_path' => $asset->filename, 'size_bytes' => $asset->size_bytes,
            'duration_ms' => $asset->duration_ms, 'sha256' => $asset->sha256,
            'status' => $existing?->status === 'ready' ? 'ready' : 'missing',
            'last_reported_at' => gmdate('Y-m-d H:i:s'),
        ];
        $db->transBegin();
        try {
            $saved = $existing ? $model->update($existing->id, $values) : $model->insert($values, false);
            if ($saved === false) throw new RuntimeException('Assignment save failed.');
            $this->bumpDeviceAssetRevision((int) $device->id);
            if ($db->transStatus() === false) throw new RuntimeException('Assignment transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            log_message('error', 'Asset assignment failed: {message}', ['message' => $error->getMessage()]);
            return redirect()->to('/control/assets')->with('error', 'The asset assignment could not be saved.');
        }
        return redirect()->to('/control/assets')->with('success', 'Asset assigned. The Studio Player will begin downloading it after the next heartbeat.');
    }

    public function assignSelection(string $publicId): RedirectResponse
    {
        return $this->assignMany($publicId, false);
    }

    public function assignGlobal(string $publicId): RedirectResponse
    {
        return $this->assignMany($publicId, true);
    }

    public function unassignSelection(string $publicId): RedirectResponse
    {
        return $this->unassignMany($publicId, false);
    }

    public function unassignGlobal(string $publicId): RedirectResponse
    {
        return $this->unassignMany($publicId, true);
    }

    public function approve(string $publicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $model = new AssetModel();
        $asset = $model->where('public_id', $publicId)->first();
        if ($asset === null) return redirect()->to('/control/assets')->with('error', 'Asset was not found.');
        if ($asset->status !== 'draft') {
            return redirect()->to('/control/assets')->with('error', 'Only a Draft revision can be approved.');
        }
        $reviewerId = (int) $this->currentUser()->id;
        $reviewedAt = gmdate('Y-m-d H:i:s');
        $db = Database::connect();
        $db->transBegin();
        try {
            $version = $this->currentVersionOrCreate($asset);
            if (! $model->update($asset->id, [
                'status' => 'active', 'reviewed_by' => $reviewerId,
                'reviewed_at' => $reviewedAt, 'rejection_reason' => null,
            ])) throw new RuntimeException('Asset approval failed.');
            if (! (new AssetVersionModel())->update($version->id, [
                'status' => 'approved', 'reviewed_by' => $reviewerId,
                'reviewed_at' => $reviewedAt, 'rejection_reason' => null,
            ])) throw new RuntimeException('Revision approval failed.');
            if ($db->transStatus() === false) throw new RuntimeException('Approval transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            return redirect()->to('/control/assets')->with('error', 'The asset revision could not be approved.');
        }
        return redirect()->to('/control/assets')->with('success', 'Film approved. It can now be assigned to a Studio.');
    }

    public function reject(string $publicId): RedirectResponse
    {
        $reason = trim((string) $this->request->getPost('rejection_reason'));
        if ($reason === '' || mb_strlen($reason) > 1000) {
            return redirect()->to('/control/assets')->with('error', 'A rejection reason is required and must not exceed 1000 characters.');
        }
        $model = new AssetModel();
        $asset = $model->where('public_id', $publicId)->first();
        if ($asset === null) return redirect()->to('/control/assets')->with('error', 'Asset was not found.');
        if ($asset->status !== 'draft') {
            return redirect()->to('/control/assets')->with('error', 'Only Draft assets can be rejected.');
        }
        $reviewerId = (int) $this->currentUser()->id;
        $reviewedAt = gmdate('Y-m-d H:i:s');
        $db = Database::connect();
        $db->transBegin();
        try {
            $version = $this->currentVersionOrCreate($asset);
            if (! $model->update($asset->id, [
                'status' => 'rejected', 'reviewed_by' => $reviewerId,
                'reviewed_at' => $reviewedAt, 'rejection_reason' => $reason,
            ])) throw new RuntimeException('Asset rejection failed.');
            if (! (new AssetVersionModel())->update($version->id, [
                'status' => 'rejected', 'reviewed_by' => $reviewerId,
                'reviewed_at' => $reviewedAt, 'rejection_reason' => $reason,
            ])) throw new RuntimeException('Revision rejection failed.');
            if ($db->transStatus() === false) throw new RuntimeException('Rejection transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            return redirect()->to('/control/assets')->with('error', 'The asset revision could not be rejected.');
        }
        return redirect()->to('/control/assets')->with('success', 'Film rejected. The distributor can see the review reason.');
    }

    public function unassign(string $publicId, string $devicePublicId): RedirectResponse
    {
        $asset = (new AssetModel())->where('public_id', $publicId)->first();
        $device = (new DeviceModel())->where('public_id', $devicePublicId)->first();
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Asset assignment was not found.');
        $model = new DeviceAssetModel();
        $assignment = $model->where('device_id', $device->id)->where('asset_id', $asset->id)->first();
        if ($assignment !== null) {
            $db = Database::connect();
            $db->transBegin();
            try {
                if (! $model->delete($assignment->id)) throw new RuntimeException('Assignment delete failed.');
                $this->bumpDeviceAssetRevision((int) $device->id);
                if ($db->transStatus() === false) throw new RuntimeException('Unassign transaction failed.');
                $db->transCommit();
            } catch (Throwable $error) {
                $db->transRollback();
                return redirect()->to('/control/assets')->with('error', 'The asset could not be unassigned.');
            }
        }
        return redirect()->to('/control/assets')->with('success', 'Asset assignment removed. The existing local file is retained for safe cleanup later.');
    }

    public function unassignAndRemove(string $publicId, string $devicePublicId): RedirectResponse
    {
        $asset = (new AssetModel())->where('public_id', $publicId)->first();
        $device = (new DeviceModel())->where('public_id', $devicePublicId)->first();
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Asset assignment was not found.');
        $model = new DeviceAssetModel();
        $assignment = $model->where('device_id', $device->id)->where('asset_id', $asset->id)->first();
        if ($assignment === null) return redirect()->to('/control/assets')->with('error', 'Asset assignment was not found.');
        $db = Database::connect();
        $db->transBegin();
        try {
            if (! $model->update($assignment->id, ['status' => 'removal_pending', 'last_reported_at' => gmdate('Y-m-d H:i:s')])) {
                throw new RuntimeException('Removal request save failed.');
            }
            $this->bumpDeviceAssetRevision((int) $device->id);
            if ($db->transStatus() === false) throw new RuntimeException('Removal request transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            return redirect()->to('/control/assets')->with('error', 'The Player removal request could not be saved.');
        }
        return redirect()->to('/control/assets')->with('success', 'Removal requested. The Player will delete its local copy when it is safe and acknowledge the request.');
    }

    private function assignMany(string $publicId, bool $global): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $asset = (new AssetModel())->where('public_id', $publicId)->where('status', 'active')->first();
        if ($asset === null) return $this->distributionRedirect($publicId, 'error', 'Only an active film can be distributed.');

        $selectedLocations = array_flip($this->postedIds('location_ids'));
        $selectedStudios = array_flip($this->postedIds('device_ids'));
        if (! $global && $selectedLocations === [] && $selectedStudios === []) {
            return $this->distributionRedirect($publicId, 'error', 'Choose at least one Location or Studio.');
        }

        $activeLocations = [];
        foreach ((new LocationModel())->where('status', 'active')->findAll() as $location) {
            $activeLocations[(int) $location->id] = (string) $location->public_id;
        }

        $targets = [];
        $incompatible = 0;
        foreach ((new DeviceModel())->where('status', 'active')->findAll() as $device) {
            $locationPublicId = $device->location_id !== null ? ($activeLocations[(int) $device->location_id] ?? null) : null;
            if ($locationPublicId === null) continue;
            $selected = $global || isset($selectedLocations[$locationPublicId]) || isset($selectedStudios[(string) $device->public_id]);
            if (! $selected) continue;
            if ($asset->encryption_format === LdgCryptoService::FORMAT && $device->ldg_version !== LdgCryptoService::FORMAT) {
                $incompatible++;
                continue;
            }
            $targets[(int) $device->id] = $device;
        }

        if ($targets === []) {
            $message = $incompatible > 0
                ? "No compatible Studio was assigned. {$incompatible} Player(s) must report LDG v1 support first."
                : 'No active Studio matched this distribution selection.';
            return $this->distributionRedirect($publicId, 'error', $message);
        }

        $model = new DeviceAssetModel();
        $db = Database::connect();
        $mediaKey = 'managed:' . $asset->public_id;
        $assigned = 0;
        $alreadyAssigned = 0;
        $db->transBegin();
        try {
            foreach ($targets as $device) {
                $existing = $model->where('device_id', $device->id)->where('media_key', $mediaKey)->first();
                if ($existing !== null && $existing->status !== 'removal_pending') {
                    $alreadyAssigned++;
                    continue;
                }
                $values = [
                    'device_id' => $device->id, 'asset_id' => $asset->id, 'media_key' => $mediaKey,
                    'source' => 'managed', 'title' => $asset->title, 'filename' => $asset->filename,
                    'relative_path' => $asset->filename, 'size_bytes' => $asset->size_bytes,
                    'duration_ms' => $asset->duration_ms, 'sha256' => $asset->sha256,
                    'status' => 'missing', 'last_reported_at' => gmdate('Y-m-d H:i:s'),
                ];
                $saved = $existing ? $model->update($existing->id, $values) : $model->insert($values, false);
                if ($saved === false) throw new RuntimeException('Bulk assignment save failed.');
                $this->bumpDeviceAssetRevision((int) $device->id);
                $assigned++;
            }
            if ($db->transStatus() === false) throw new RuntimeException('Bulk assignment transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            log_message('error', 'Bulk asset assignment failed: {message}', ['message' => $error->getMessage()]);
            return $this->distributionRedirect($publicId, 'error', 'The distribution could not be saved. No partial assignment was kept.');
        }

        $parts = ["{$assigned} Studio(s) assigned"];
        if ($alreadyAssigned > 0) $parts[] = "{$alreadyAssigned} already assigned";
        if ($incompatible > 0) $parts[] = "{$incompatible} incompatible Player(s) skipped";
        return $this->distributionRedirect($publicId, 'success', implode('; ', $parts) . '.');
    }

    private function unassignMany(string $publicId, bool $global): RedirectResponse
    {
        $asset = (new AssetModel())->where('public_id', $publicId)->first();
        if ($asset === null) return $this->distributionRedirect($publicId, 'error', 'Film was not found.');

        $selectedLocations = array_flip($this->postedIds('location_ids'));
        $selectedStudios = array_flip($this->postedIds('device_ids'));
        if (! $global && $selectedLocations === [] && $selectedStudios === []) {
            return $this->distributionRedirect($publicId, 'error', 'Choose at least one Location or Studio.');
        }
        $mode = trim((string) $this->request->getPost('removal_mode'));
        if (! in_array($mode, ['retain', 'remove'], true)) {
            return $this->distributionRedirect($publicId, 'error', 'Choose whether the Player should retain or remove its local file.');
        }

        $locations = [];
        foreach ((new LocationModel())->findAll() as $location) $locations[(int) $location->id] = (string) $location->public_id;
        $assignments = (new DeviceAssetModel())->where('asset_id', $asset->id)->findAll();
        $devices = [];
        foreach ((new DeviceModel())->findAll() as $device) $devices[(int) $device->id] = $device;

        $targets = [];
        foreach ($assignments as $assignment) {
            $device = $devices[(int) $assignment->device_id] ?? null;
            if ($device === null) continue;
            $locationPublicId = $device->location_id !== null ? ($locations[(int) $device->location_id] ?? null) : null;
            if (! $global && ! isset($selectedStudios[(string) $device->public_id]) && ($locationPublicId === null || ! isset($selectedLocations[$locationPublicId]))) continue;
            $targets[(int) $assignment->id] = [$assignment, $device];
        }
        if ($targets === []) return $this->distributionRedirect($publicId, 'error', 'No current assignment matched this selection.');

        $model = new DeviceAssetModel();
        $db = Database::connect();
        $changed = 0;
        $alreadyPending = 0;
        $db->transBegin();
        try {
            foreach ($targets as [$assignment, $device]) {
                if ($mode === 'remove') {
                    if ($assignment->status === 'removal_pending') {
                        $alreadyPending++;
                        continue;
                    }
                    if (! $model->update($assignment->id, ['status' => 'removal_pending', 'last_reported_at' => gmdate('Y-m-d H:i:s')])) {
                        throw new RuntimeException('Bulk removal request save failed.');
                    }
                } elseif (! $model->delete($assignment->id)) {
                    throw new RuntimeException('Bulk unassignment delete failed.');
                }
                $this->bumpDeviceAssetRevision((int) $device->id);
                $changed++;
            }
            if ($db->transStatus() === false) throw new RuntimeException('Bulk unassignment transaction failed.');
            $db->transCommit();
        } catch (Throwable $error) {
            $db->transRollback();
            log_message('error', 'Bulk asset unassignment failed: {message}', ['message' => $error->getMessage()]);
            return $this->distributionRedirect($publicId, 'error', 'The distribution change failed. No partial change was kept.');
        }

        $message = $mode === 'remove'
            ? "Removal requested for {$changed} Studio(s)"
            : "{$changed} Studio assignment(s) removed; local files were retained";
        if ($alreadyPending > 0) $message .= "; {$alreadyPending} removal request(s) already pending";
        return $this->distributionRedirect($publicId, 'success', $message . '.');
    }

    /** @return list<string> */
    private function postedIds(string $field): array
    {
        $value = $this->request->getPost($field);
        if (! is_array($value)) return [];
        $ids = [];
        foreach ($value as $id) {
            $id = trim((string) $id);
            if ($id !== '' && mb_strlen($id) <= 80) $ids[$id] = $id;
        }
        return array_values($ids);
    }

    private function distributionRedirect(string $publicId, string $flashType, string $message): RedirectResponse
    {
        return redirect()->to('/control/library/' . rawurlencode($publicId) . '#distribution')->with($flashType, $message);
    }

    public function delete(string $publicId): RedirectResponse
    {
        $assetModel = new AssetModel();
        $asset = $assetModel->where('public_id', $publicId)->first();
        if ($asset === null) return redirect()->to('/control/assets')->with('error', 'Asset was not found.');
        if ((new DeviceAssetModel())->where('asset_id', $asset->id)->countAllResults() > 0) {
            return redirect()->to('/control/assets')->with('error', 'Unassign this asset from every Player and wait for pending removals before deleting it.');
        }
        $db = Database::connect();
        if ($db->table('schedule_items')->where('asset_id', $asset->id)->countAllResults() > 0) {
            return redirect()->to('/control/assets')->with('error', 'This asset is referenced by a schedule and cannot be deleted.');
        }

        $storage = new StorageManager();
        $pathService = new AssetStoragePathService();
        $objects = [];
        $assetDirectories = [];
        $assetProfile = $storage->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
        $objects[(int) $assetProfile->id . ':' . (string) $asset->storage_key] = [$assetProfile, (string) $asset->storage_key];
        $assetDirectory = $pathService->assetDirectoryKey((string) $asset->storage_key);
        if ($assetDirectory !== null) $assetDirectories[(int) $assetProfile->id . ':' . $assetDirectory] = [$assetProfile, $assetDirectory];
        foreach ((new AssetVersionModel())->where('asset_id', $asset->id)->findAll() as $version) {
            $profile = $storage->profile($version->storage_profile_id === null ? null : (int) $version->storage_profile_id);
            $objects[(int) $profile->id . ':' . (string) $version->storage_key] = [$profile, (string) $version->storage_key];
            $assetDirectory = $pathService->assetDirectoryKey((string) $version->storage_key);
            if ($assetDirectory !== null) $assetDirectories[(int) $profile->id . ':' . $assetDirectory] = [$profile, $assetDirectory];
        }
        if ((string) $asset->poster_storage_key !== '') {
            $objects[(int) $assetProfile->id . ':' . (string) $asset->poster_storage_key] = [$assetProfile, (string) $asset->poster_storage_key];
        }
        $stagedFiles = [];
        $transactionStarted = false;
        try {
            if ($objects !== []) {
                $stagingDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . '.delete-staging';
                if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
                    throw new RuntimeException('The deletion staging directory could not be created.');
                }
                foreach ($objects as [$profile, $storageKey]) {
                    $originalPath = $storage->materialize($profile, $storageKey);
                    if ($originalPath === null) continue;
                    $stagedPath = $stagingDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '-' . basename($originalPath);
                    if (! rename($originalPath, $stagedPath)) throw new RuntimeException('An asset revision file could not be staged for deletion.');
                    $stagedFiles[] = ['original' => $originalPath, 'staged' => $stagedPath, 'profile' => $profile, 'key' => $storageKey];
                }
            }

            $db->transBegin();
            $transactionStarted = true;
            if (! $assetModel->delete($asset->id) || $db->transStatus() === false) throw new RuntimeException('The asset database record could not be deleted.');
            $db->transCommit();
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if ($transactionStarted) $db->transRollback();
            foreach (array_reverse($stagedFiles) as $stagedFile) {
                if (is_file($stagedFile['staged'])) @rename($stagedFile['staged'], $stagedFile['original']);
            }
            log_message('error', 'Asset deletion failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/assets')->with('error', 'The asset could not be deleted safely.');
        }

        $cleanupFailed = false;
        foreach ($objects as [$profile, $storageKey]) {
            try {
                $storage->delete($profile, $storageKey);
            } catch (Throwable $error) {
                $cleanupFailed = true;
                log_message('error', 'Deleted asset storage cleanup failed: {message}', ['message' => $error->getMessage()]);
            }
        }
        foreach ($assetDirectories as [$profile, $directoryKey]) {
            try {
                $storage->deleteEmptyDirectory($profile, $directoryKey);
            } catch (Throwable $error) {
                $cleanupFailed = true;
                log_message('error', 'Deleted asset directory cleanup failed: {message}', ['message' => $error->getMessage()]);
            }
        }
        foreach ($stagedFiles as $stagedFile) {
            if (is_file($stagedFile['staged']) && ! @unlink($stagedFile['staged'])) {
                $cleanupFailed = true;
                log_message('error', 'Deleted asset revision remains in staging: {path}', ['path' => $stagedFile['staged']]);
            }
        }
        if ($cleanupFailed) {
            return redirect()->to('/control/assets')->with('error', 'The database record was deleted, but one or more staged revision files require manual cleanup.');
        }
        return redirect()->to('/control/assets')->with('success', 'Asset file and database record permanently deleted.');
    }

    private function accessibleAsset(string $publicId): ?object
    {
        $currentUser = $this->currentUser();
        $query = (new AssetModel())->where('public_id', $publicId);
        if ($currentUser->role !== 'admin') $query->where('created_by', $currentUser->id);
        return $query->first();
    }

    private function currentVersionOrCreate(object $asset): object
    {
        $model = new AssetVersionModel();
        $revision = max(1, (int) $asset->revision);
        $existing = $model->where('asset_id', $asset->id)->where('revision', $revision)->first();
        if ($existing !== null) return $existing;
        $id = $model->insert([
            'asset_id' => $asset->id, 'revision' => $revision,
            'filename' => $asset->filename, 'storage_key' => $asset->storage_key,
            'storage_profile_id' => $asset->storage_profile_id,
            'mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes,
            'sha256' => $asset->sha256, 'duration_ms' => $asset->duration_ms,
            'encryption_format' => $asset->encryption_format,
            'plaintext_size_bytes' => $asset->plaintext_size_bytes,
            'plaintext_sha256' => $asset->plaintext_sha256,
            'ldg_chunk_size' => $asset->ldg_chunk_size,
            'wrapped_dek' => $asset->wrapped_dek,
            'dek_nonce' => $asset->dek_nonce,
            'dek_tag' => $asset->dek_tag,
            'key_version' => $asset->key_version,
            'encryption_revision' => $asset->encryption_revision,
            'status' => match ($asset->status) {
                'active' => 'approved', 'rejected' => 'rejected', 'expired' => 'expired', default => 'draft',
            },
            'metadata_snapshot' => $this->versionMetadataSnapshot($asset),
            'submitted_by' => $asset->created_by, 'reviewed_by' => $asset->reviewed_by,
            'reviewed_at' => $asset->reviewed_at, 'rejection_reason' => $asset->rejection_reason,
        ], true);
        if (! is_int($id)) throw new RuntimeException('Current revision history could not be initialized.');
        return $model->find($id);
    }

    /** @param array<string, mixed>|object $source */
    private function versionMetadataSnapshot(array|object $source): string
    {
        $fields = ['title', 'asset_type', 'synopsis', 'genre', 'language', 'subtitles', 'age_rating', 'production_year', 'release_date', 'expires_on', 'distributor_company'];
        $snapshot = [];
        foreach ($fields as $field) {
            $value = is_array($source) ? ($source[$field] ?? null) : ($source->{$field} ?? null);
            if (is_object($value) && method_exists($value, 'format')) $value = $value->format('Y-m-d');
            $snapshot[$field] = $value;
        }
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @return array<string, mixed> */
    private function metadataInput(string $fallbackTitle): array
    {
        $text = static fn ($value): ?string => trim((string) $value) !== '' ? trim((string) $value) : null;
        $year = trim((string) $this->request->getPost('production_year'));
        return [
            'title' => trim((string) $this->request->getPost('title')) ?: $fallbackTitle,
            'asset_type' => trim((string) $this->request->getPost('asset_type')) ?: 'featured',
            'synopsis' => $text($this->request->getPost('synopsis')),
            'genre' => $text($this->request->getPost('genre')),
            'language' => $text($this->request->getPost('language')),
            'subtitles' => $text($this->request->getPost('subtitles')),
            'age_rating' => $text($this->request->getPost('age_rating')),
            'production_year' => $year === '' ? null : (int) $year,
            'release_date' => $text($this->request->getPost('release_date')),
            'expires_on' => $text($this->request->getPost('expires_on')),
            'distributor_company' => $text($this->request->getPost('distributor_company')),
        ];
    }

    /** @param array<string, mixed> $metadata @return array<string, string> */
    private function metadataErrors(array $metadata, bool $allowPastExpiration = false): array
    {
        $errors = [];
        if ($metadata['title'] === '' || mb_strlen($metadata['title']) > 255) $errors['title'] = 'Title is required and must not exceed 255 characters.';
        if (! in_array($metadata['asset_type'], AssetTaxonomyService::TYPES, true)) $errors['asset_type'] = 'Choose a valid asset type.';
        foreach (['genre' => 120, 'language' => 80, 'subtitles' => 160, 'age_rating' => 20, 'distributor_company' => 180] as $field => $limit) {
            if ($metadata[$field] !== null && mb_strlen((string) $metadata[$field]) > $limit) $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$limit} characters.";
        }
        if ($metadata['age_rating'] !== null && ! in_array($metadata['age_rating'], self::AGE_RATINGS, true)) {
            $errors['age_rating'] = 'Age rating is invalid.';
        }
        if ($metadata['synopsis'] !== null && mb_strlen((string) $metadata['synopsis']) > 5000) $errors['synopsis'] = 'Synopsis must not exceed 5000 characters.';
        $year = $metadata['production_year'];
        if ($year !== null && ($year < 1888 || $year > (int) date('Y') + 2)) $errors['production_year'] = 'Production year is outside the accepted range.';
        if ($metadata['release_date'] !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $metadata['release_date']);
            if ($date === false || $date->format('Y-m-d') !== $metadata['release_date']) $errors['release_date'] = 'Release date is invalid.';
        }
        if ($metadata['expires_on'] !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $metadata['expires_on']);
            if ($date === false || $date->format('Y-m-d') !== $metadata['expires_on']) {
                $errors['expires_on'] = 'Expiration date is invalid.';
            } elseif (! $allowPastExpiration && $metadata['expires_on'] < (new AssetExpiryService())->today()) {
                $errors['expires_on'] = 'Expiration date cannot be in the past.';
            }
        }
        return $errors;
    }

    /** @return array{ids:?list<int>,names:list<string>} */
    private function taxonomyInput(): array
    {
        if ($this->request->getPost('genres_present') === null && ! is_array($this->request->getPost('genre_ids'))) {
            return ['ids' => null, 'names' => []];
        }
        $taxonomy = new AssetTaxonomyService();
        $ids = $taxonomy->validateGenreIds($this->request->getPost('genre_ids'));
        return ['ids' => $ids, 'names' => $taxonomy->namesForIds($ids)];
    }

    /** @param list<string> $names */
    private function legacyGenreSummary(array $names): ?string
    {
        if ($names === []) return null;
        return mb_substr(implode(', ', $names), 0, 120);
    }

    private function bumpDeviceAssetRevision(int $deviceId): void
    {
        $updated = Database::connect()->table('devices')->where('id', $deviceId)
            ->set('asset_revision', 'asset_revision + 1', false)
            ->set('updated_at', gmdate('Y-m-d H:i:s'))->update();
        if (! $updated) throw new RuntimeException('Player asset revision could not be incremented.');
        (new RealtimeOutboxService())->queueDevice($deviceId, 'asset.revision.changed');
    }

    /** @return array<string, string> */
    private function posterInfo(?object $poster): array
    {
        if ($poster === null || $poster->getError() === UPLOAD_ERR_NO_FILE) return [];
        if (! $poster->isValid() || $poster->hasMoved()) return ['error' => 'The poster upload is invalid.'];
        if ((int) $poster->getSize() > self::POSTER_MAX_BYTES) return ['error' => 'Poster size may not exceed 10 MB.'];
        $filename = basename($poster->getClientName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $poster->getMimeType());
        if (! in_array($extension, self::POSTER_EXTENSIONS, true) || ! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['error' => 'Poster must be a JPG, PNG, or WebP image.'];
        }
        return ['filename' => $filename, 'extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime_type' => $mimeType];
    }

    private function currentUser(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }

    private function uploadFailure(string $message, int $status = 422): ResponseInterface
    {
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode($status)->setJSON([
                'error' => ['code' => 'asset_upload_failed', 'message' => $message],
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
        return redirect()->back()->withInput()->with('error', $message);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
