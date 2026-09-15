<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AssetExpiryService;
use App\Libraries\AssetStoragePathService;
use App\Libraries\AssetTaxonomyService;
use App\Libraries\LdgCryptoService;
use App\Libraries\MediaMetadataService;
use App\Libraries\ResumableUploadService;
use App\Libraries\MediaUploadWorkerLauncher;
use App\Libraries\StorageManager;
use App\Models\AssetModel;
use App\Models\AssetVersionModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;
use Throwable;

final class MediaUploadController extends BaseController
{
    private const EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'];
    private const POSTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];
    private const AGE_RATINGS = ['SU', '13+', '17+', '21+'];

    public function token(): ResponseInterface
    {
        $this->currentUser();
        return $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setJSON(['data' => [], 'csrf' => $this->csrf()]);
    }

    public function initiate(): ResponseInterface
    {
        try {
            $user = $this->currentUser();
            $purpose = trim((string) $this->request->getPost('purpose'));
            $filename = basename((string) $this->request->getPost('filename'));
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (! in_array($extension, self::EXTENSIONS, true)) throw new RuntimeException('The selected media type is not supported.');

            $target = null;
            $metadata = [];
            if ($purpose === 'asset') {
                $metadata = $this->assetMetadata($filename);
            } elseif ($purpose === 'revision') {
                $target = $this->revisionTarget((string) $this->request->getPost('target_asset_id'), $user);
                $metadata['_revision'] = max(1, (int) $target->revision) + 1;
            } else {
                throw new RuntimeException('Upload purpose is invalid.');
            }

            $service = new ResumableUploadService();
            $session = $service->initiate([
                'owner_user_id' => (int) $user->id,
                'purpose' => $purpose,
                'target_asset_id' => $target?->id,
                'fingerprint' => (string) $this->request->getPost('fingerprint'),
                'filename' => $filename,
                'mime_type' => (string) $this->request->getPost('mime_type'),
                'size_bytes' => $this->request->getPost('size_bytes'),
                'last_modified_ms' => $this->request->getPost('last_modified_ms'),
                'metadata' => $metadata,
                'result_public_id' => $target?->public_id,
            ]);

            if ($purpose === 'asset') {
                $poster = $this->request->getFile('poster');
                $posterInfo = $this->posterInfo($poster);
                if (isset($posterInfo['error'])) throw new RuntimeException($posterInfo['error']);
                if ($posterInfo !== []) $session = $service->storePoster($session, $poster, $posterInfo);
            }
            return $this->success($service, $session);
        } catch (Throwable $error) {
            return $this->failure($error->getMessage(), 422);
        }
    }

    public function status(string $publicId): ResponseInterface
    {
        try {
            $service = new ResumableUploadService();
            $service->cleanupExpired();
            $session = $this->owned($service, $publicId);
            return $this->success($service, $session);
        } catch (Throwable $error) {
            return $this->failure($error->getMessage(), 404);
        }
    }

    public function chunk(string $publicId): ResponseInterface
    {
        try {
            $service = new ResumableUploadService();
            $service->cleanupExpired();
            $session = $this->owned($service, $publicId);
            $file = $this->request->getFile('chunk');
            if ($file === null) throw new RuntimeException('Chunk file is required.');
            $index = filter_var($this->request->getPost('index'), FILTER_VALIDATE_INT);
            if ($index === false || $index < 0) throw new RuntimeException('Chunk index is invalid.');
            $session = $service->appendChunk($session, $file, $index, strtolower((string) $this->request->getPost('sha256')));
            return $this->success($service, $session);
        } catch (Throwable $error) {
            return $this->failure($error->getMessage(), 409);
        }
    }

    public function finalize(string $publicId): ResponseInterface
    {
        $service = new ResumableUploadService();
        try {
            $service->cleanupExpired();
            $session = $this->owned($service, $publicId);
            if ($session->status === 'completed') return $this->success($service, $session);
            $session = $service->queueProcessing($session);
            if ($session->status === 'queued') {
                (new MediaUploadWorkerLauncher())->launch((string) $session->public_id);
            }
            $session = $service->find((string) $session->public_id) ?? $session;
            return $this->success($service, $session, [
                'message' => 'Encrypted media processing started in the background.',
            ])->setStatusCode(202);
        } catch (Throwable $error) {
            if (isset($session) && in_array($session->status, ['queued', 'processing'], true)) $session = $service->fail($session, $error->getMessage());
            log_message('error', 'Resumable media processing could not start: {message}', ['message' => $error->getMessage()]);
            return $this->failure($error->getMessage(), 500, isset($session) ? $service->payload($session) : null);
        }
    }

    public function cancel(string $publicId): ResponseInterface
    {
        try {
            $service = new ResumableUploadService();
            $service->cleanupExpired();
            $session = $this->owned($service, $publicId);
            if (in_array($session->status, ['queued', 'processing'], true)) throw new RuntimeException('A media file cannot be cancelled while it is being finalized.');
            $service->cancel($session);
            return $this->response->setJSON(['data' => ['cancelled' => true], 'csrf' => $this->csrf()]);
        } catch (Throwable $error) {
            return $this->failure($error->getMessage(), 409);
        }
    }

    /** @return array{public_id:string,message:string,redirect_url:string} */
    private function finalizeAsset(ResumableUploadService $uploads, object $session): array
    {
        $metadata = $uploads->metadata($session);
        $genreIds = array_map('intval', (array) ($metadata['_genre_ids'] ?? []));
        unset($metadata['_genre_ids'], $metadata['_poster']);
        $publicId = (string) $session->result_public_id;
        $existing = (new AssetModel())->where('public_id', $publicId)->first();
        if ($existing !== null) {
            return ['public_id' => $publicId, 'message' => 'Asset upload was already finalized.', 'redirect_url' => site_url('control/library/' . $publicId)];
        }
        $storageKey = (new AssetStoragePathService())->newMediaKey((string) $metadata['title'], $publicId, 1);
        $storage = new StorageManager();
        $profile = $storage->defaultProfile();
        $encryptedTemporaryPath = $storage->temporaryPath('.ldg');
        $storedMedia = false;
        $storedPosterKey = null;
        try {
            $sourcePath = $uploads->sourcePath($session);
            $durationMs = (new MediaMetadataService())->detectDurationMs($sourcePath);
            $encryptionValues = (new LdgCryptoService())->encryptFile($sourcePath, $encryptedTemporaryPath, $publicId, 1);
            $storage->putFile($profile, $encryptedTemporaryPath, $storageKey);
            $storedMedia = true;
            $posterValues = ['poster_storage_key' => null, 'poster_filename' => null, 'poster_mime_type' => null];
            $posterPath = $uploads->posterPath($session);
            $allMetadata = $uploads->metadata($session);
            $posterInfo = (array) ($allMetadata['_poster'] ?? []);
            if ($posterPath !== null && is_file($posterPath) && $posterInfo !== []) {
                $storedPosterKey = 'posters/' . $publicId . '.' . $posterInfo['extension'];
                $storage->putFile($profile, $posterPath, $storedPosterKey);
                $posterValues = [
                    'poster_storage_key' => $storedPosterKey,
                    'poster_filename' => $posterInfo['filename'],
                    'poster_mime_type' => $posterInfo['mime_type'],
                ];
            }
            $user = $this->currentUser();
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
    private function finalizeRevision(ResumableUploadService $uploads, object $session): array
    {
        $asset = (new AssetModel())->find((int) $session->target_asset_id);
        $user = $this->currentUser();
        $metadata = $uploads->metadata($session);
        $revision = max(2, (int) ($metadata['_revision'] ?? 0));
        $existingVersion = $asset === null ? null : (new AssetVersionModel())
            ->where('asset_id', $asset->id)->where('revision', $revision)->first();
        if ($asset !== null && $existingVersion !== null) {
            return [
                'public_id' => (string) $asset->public_id,
                'message' => "Revision {$revision} was already finalized.",
                'redirect_url' => site_url('control/library/' . $asset->public_id),
            ];
        }
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
            $encryptionValues = (new LdgCryptoService())->encryptFile($sourcePath, $temporaryPath, (string) $asset->public_id, $revision);
            $storage->putFile($profile, $temporaryPath, $storageKey);
            $stored = true;
            $fileValues = [
                'filename' => $session->filename, 'storage_key' => $storageKey,
                'storage_profile_id' => (int) $profile->id, 'mime_type' => $session->mime_type,
                ...$encryptionValues, 'duration_ms' => (new MediaMetadataService())->detectDurationMs($sourcePath),
            ];
            $db = Database::connect();
            $db->transBegin();
            if (! (new AssetModel())->update($asset->id, [
                ...$fileValues, 'revision' => $revision, 'status' => 'draft',
                'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null,
            ])) throw new RuntimeException('Corrected asset could not be saved.');
            $versionId = (new AssetVersionModel())->insert([
                'asset_id' => $asset->id, 'revision' => $revision, ...$fileValues,
                'status' => 'draft', 'metadata_snapshot' => $this->versionMetadataSnapshot($asset),
                'submitted_by' => (int) $user->id,
            ], true);
            if (! is_int($versionId) || $db->transStatus() === false) throw new RuntimeException('Corrected revision could not be saved.');
            $db->transCommit();
            return [
                'public_id' => (string) $asset->public_id,
                'message' => "Revision {$revision} submitted as Draft for administrator review.",
                'redirect_url' => site_url('control/library/' . $asset->public_id),
            ];
        } catch (Throwable $error) {
            if (isset($db)) $db->transRollback();
            if ($stored) try { $storage->delete($profile, $storageKey); } catch (Throwable) {}
            throw $error;
        } finally {
            if (is_file($temporaryPath)) @unlink($temporaryPath);
        }
    }

    /** @return array<string,mixed> */
    private function assetMetadata(string $filename): array
    {
        $text = static fn ($value): ?string => trim((string) $value) !== '' ? trim((string) $value) : null;
        $year = trim((string) $this->request->getPost('production_year'));
        $metadata = [
            'title' => trim((string) $this->request->getPost('title')) ?: pathinfo($filename, PATHINFO_FILENAME),
            'asset_type' => trim((string) $this->request->getPost('asset_type')) ?: 'featured',
            'synopsis' => $text($this->request->getPost('synopsis')),
            'genre' => null, 'language' => $text($this->request->getPost('language')),
            'subtitles' => $text($this->request->getPost('subtitles')),
            'age_rating' => $text($this->request->getPost('age_rating')),
            'production_year' => $year === '' ? null : (int) $year,
            'release_date' => $text($this->request->getPost('release_date')),
            'expires_on' => $text($this->request->getPost('expires_on')),
            'distributor_company' => $text($this->request->getPost('distributor_company')),
        ];
        $errors = [];
        if ($metadata['title'] === '' || mb_strlen($metadata['title']) > 255) $errors[] = 'Title is required and must not exceed 255 characters.';
        if (! in_array($metadata['asset_type'], AssetTaxonomyService::TYPES, true)) $errors[] = 'Choose a valid asset type.';
        if ($metadata['age_rating'] !== null && ! in_array($metadata['age_rating'], self::AGE_RATINGS, true)) $errors[] = 'Age rating is invalid.';
        if ($metadata['synopsis'] !== null && mb_strlen($metadata['synopsis']) > 5000) $errors[] = 'Synopsis must not exceed 5,000 characters.';
        if ($metadata['production_year'] !== null && ($metadata['production_year'] < 1888 || $metadata['production_year'] > (int) date('Y') + 2)) $errors[] = 'Production year is invalid.';
        foreach (['language' => 80, 'subtitles' => 160, 'distributor_company' => 180] as $field => $limit) {
            if ($metadata[$field] !== null && mb_strlen((string) $metadata[$field]) > $limit) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is too long.';
        }
        foreach (['release_date', 'expires_on'] as $field) {
            if ($metadata[$field] === null) continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $metadata[$field]);
            if ($date === false || $date->format('Y-m-d') !== $metadata[$field]) $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is invalid.';
        }
        if ($metadata['expires_on'] !== null && $metadata['expires_on'] < (new AssetExpiryService())->today()) {
            $errors[] = 'Expiration date cannot be in the past.';
        }
        if ($errors !== []) throw new RuntimeException($errors[0]);
        $taxonomy = new AssetTaxonomyService();
        $genreIds = $taxonomy->validateGenreIds($this->request->getPost('genre_ids'));
        $metadata['genre'] = $genreIds === [] ? null : mb_substr(implode(', ', $taxonomy->namesForIds($genreIds)), 0, 120);
        $metadata['_genre_ids'] = $genreIds;
        return $metadata;
    }

    private function revisionTarget(string $publicId, object $user): object
    {
        $asset = (new AssetModel())->where('public_id', trim($publicId))->first();
        if ($asset === null || $user->role !== 'distributor' || (int) $asset->created_by !== (int) $user->id || $asset->status !== 'rejected') {
            throw new RuntimeException('Only the owning distributor can replace media for a rejected film.');
        }
        return $asset;
    }

    /** @return array<string,string> */
    private function posterInfo(?object $poster): array
    {
        if ($poster === null || $poster->getError() === UPLOAD_ERR_NO_FILE) return [];
        if (! $poster->isValid() || $poster->hasMoved() || (int) $poster->getSize() > 10485760) return ['error' => 'Poster upload is invalid or exceeds 10 MB.'];
        $filename = basename($poster->getClientName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower((string) $poster->getMimeType());
        if (! in_array($extension, self::POSTER_EXTENSIONS, true) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return ['error' => 'Poster must be a JPG, PNG, or WebP image.'];
        return ['filename' => $filename, 'extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime_type' => $mime];
    }

    private function owned(ResumableUploadService $service, string $publicId): object
    {
        $session = $service->owned($publicId, (int) $this->currentUser()->id);
        if ($session === null) throw new RuntimeException('Upload session was not found.');
        return $session;
    }

    /** @param array<string,mixed> $extra */
    private function success(ResumableUploadService $service, object $session, array $extra = []): ResponseInterface
    {
        if ($session->status === 'completed' && $session->result_public_id && ! isset($extra['redirect_url'])) {
            $extra['redirect_url'] = site_url('control/library/' . $session->result_public_id);
            $extra['message'] = 'Upload is already complete.';
        }
        return $this->response->setJSON(['data' => ['session' => $service->payload($session), ...$extra], 'csrf' => $this->csrf()]);
    }

    /** @param array<string,mixed>|null $session */
    private function failure(string $message, int $status, ?array $session = null): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'error' => ['code' => 'media_upload_failed', 'message' => $message],
            'data' => $session === null ? null : ['session' => $session], 'csrf' => $this->csrf(),
        ]);
    }

    /** @return array{name:string,hash:string} */
    private function csrf(): array
    {
        return ['name' => csrf_token(), 'hash' => csrf_hash()];
    }

    private function currentUser(): object
    {
        $user = (new UserModel())->find((int) session()->get('cms_web_user_id'));
        if ($user === null) throw new RuntimeException('Authenticated user was not found.');
        return $user;
    }

    /** @param array<string,mixed>|object $source */
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

}
