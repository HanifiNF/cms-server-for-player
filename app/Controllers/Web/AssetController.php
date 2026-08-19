<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\UserModel;
use App\Libraries\AssetExpiryService;
use App\Libraries\MediaMetadataService;
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

    public function index(): string
    {
        $expiryService = new AssetExpiryService();
        $expiryService->expireDue();
        $currentUser = $this->currentUser();
        $isAdmin = $currentUser->role === 'admin';
        $assetQuery = (new AssetModel())->orderBy('created_at', 'DESC');
        if (! $isAdmin) $assetQuery->where('created_by', $currentUser->id);
        $scopedAssets = $assetQuery->findAll();
        $search = trim((string) $this->request->getGet('q'));
        $statusFilter = trim((string) $this->request->getGet('status'));
        $genreFilter = trim((string) $this->request->getGet('genre'));
        $distributorFilter = $isAdmin ? max(0, (int) $this->request->getGet('distributor')) : 0;
        if (! in_array($statusFilter, ['', 'draft', 'active', 'rejected', 'expired'], true)) $statusFilter = '';
        $assets = array_values(array_filter($scopedAssets, static function ($asset) use ($search, $statusFilter, $genreFilter, $distributorFilter): bool {
            if ($statusFilter !== '' && $asset->status !== $statusFilter) return false;
            if ($genreFilter !== '' && (string) $asset->genre !== $genreFilter) return false;
            if ($distributorFilter > 0 && (int) $asset->created_by !== $distributorFilter) return false;
            if ($search === '') return true;
            $haystack = implode(' ', [(string) $asset->title, (string) $asset->filename, (string) $asset->genre, (string) $asset->distributor_company]);
            return mb_stripos($haystack, $search) !== false;
        }));
        $statusCounts = ['total' => count($scopedAssets), 'draft' => 0, 'active' => 0, 'rejected' => 0, 'expired' => 0];
        $genres = [];
        foreach ($scopedAssets as $asset) {
            if (isset($statusCounts[$asset->status])) $statusCounts[$asset->status]++;
            if (trim((string) $asset->genre) !== '') $genres[(string) $asset->genre] = true;
        }
        $genres = array_keys($genres);
        sort($genres, SORT_NATURAL | SORT_FLAG_CASE);
        $devices = $isAdmin ? (new DeviceModel())->where('status', 'active')->orderBy('name')->findAll() : [];
        $assignments = [];
        $deviceNames = [];
        if ($isAdmin) {
            foreach ((new DeviceModel())->findAll() as $device) $deviceNames[(int) $device->id] = ['name' => $device->name, 'public_id' => $device->public_id];
            foreach ((new DeviceAssetModel())->where('asset_id !=', null)->findAll() as $row) {
                $assignments[(int) $row->asset_id][] = [
                    'device_id' => (int) $row->device_id,
                    'device_name' => $deviceNames[(int) $row->device_id]['name'] ?? 'Unknown Player',
                    'device_public_id' => $deviceNames[(int) $row->device_id]['public_id'] ?? '',
                    'status' => $row->status,
                ];
            }
        }
        $userNames = [];
        $distributors = [];
        foreach ((new UserModel())->findAll() as $user) {
            $userNames[(int) $user->id] = $user->name;
            if ($user->role === 'distributor') $distributors[] = $user;
        }
        return view('web/assets', [
            'title' => 'Assets', 'active' => 'assets', 'admin' => $currentUser,
            'assets' => $assets, 'devices' => $devices, 'assignments' => $assignments,
            'isAdmin' => $isAdmin, 'userNames' => $userNames,
            'statusCounts' => $statusCounts, 'genres' => $genres, 'distributors' => $distributors,
            'catalogToday' => $expiryService->today(),
            'filters' => ['q' => $search, 'status' => $statusFilter, 'genre' => $genreFilter, 'distributor' => $distributorFilter],
        ]);
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
        $metadata = $this->metadataInput(pathinfo($filename, PATHINFO_FILENAME));
        $metadataErrors = $this->metadataErrors($metadata);
        if ($metadataErrors !== []) return $this->uploadFailure(reset($metadataErrors));
        if (mb_strlen($filename) > 255) return $this->uploadFailure('The filename is too long.');
        $poster = $this->request->getFile('poster');
        $posterInfo = $this->posterInfo($poster);
        if (isset($posterInfo['error'])) return $this->uploadFailure($posterInfo['error']);

        $publicId = $this->uuidV4();
        $storageDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'assets';
        $storedName = $publicId . '.' . $extension;
        $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storedName;
        $posterStoredPath = null;
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        try {
            if (! is_dir($storageDir) && ! mkdir($storageDir, 0775, true) && ! is_dir($storageDir)) throw new \RuntimeException('Asset storage directory could not be created.');
            $file->move($storageDir, $storedName);
            $size = filesize($storedPath);
            $sha256 = hash_file('sha256', $storedPath);
            if ($size === false || $size <= 0 || $sha256 === false) throw new \RuntimeException('Uploaded media could not be inspected.');
            $durationMs = (new MediaMetadataService())->detectDurationMs($storedPath);
            $posterValues = ['poster_storage_key' => null, 'poster_filename' => null, 'poster_mime_type' => null];
            if ($posterInfo !== []) {
                $posterDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'posters';
                if (! is_dir($posterDir) && ! mkdir($posterDir, 0775, true) && ! is_dir($posterDir)) throw new RuntimeException('Poster storage directory could not be created.');
                $posterStoredName = $publicId . '.' . $posterInfo['extension'];
                $poster->move($posterDir, $posterStoredName);
                $posterStoredPath = $posterDir . DIRECTORY_SEPARATOR . $posterStoredName;
                $posterValues = [
                    'poster_storage_key' => 'posters/' . $posterStoredName,
                    'poster_filename' => $posterInfo['filename'],
                    'poster_mime_type' => $posterInfo['mime_type'],
                ];
            }
            $currentUser = $this->currentUser();
            $status = $currentUser->role === 'distributor' ? 'draft' : 'active';
            $inserted = (new AssetModel())->insert([
                'public_id' => $publicId, ...$metadata, ...$posterValues, 'filename' => $filename,
                'storage_key' => 'assets/' . $storedName, 'mime_type' => $mimeType,
                'size_bytes' => $size, 'sha256' => $sha256,
                'duration_ms' => $durationMs, 'status' => $status,
                'created_by' => (int) $currentUser->id,
            ], true);
            if ($inserted === false) throw new \RuntimeException('Asset metadata could not be stored.');
        } catch (Throwable $exception) {
            if (is_file($storedPath)) @unlink($storedPath);
            if ($posterStoredPath !== null && is_file($posterStoredPath)) @unlink($posterStoredPath);
            log_message('error', 'Asset upload failed: {message}', ['message' => $exception->getMessage()]);
            return $this->uploadFailure('The media asset could not be uploaded.', 500);
        }
        $message = $status === 'draft'
            ? 'Film uploaded as Draft and is waiting for administrator approval.'
            : ($durationMs > 0
                ? 'Asset uploaded with automatically detected duration. Assign it to a Player to start remote download.'
                : 'Asset uploaded. Duration is pending and will be detected by the first Player that downloads it.');
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
        $metadata = $this->metadataInput((string) $asset->title);
        $errors = $this->metadataErrors($metadata, $asset->status === 'expired');
        if ($errors !== []) return redirect()->to('/control/assets')->with('errors', $errors);
        $poster = $this->request->getFile('poster');
        $posterInfo = $this->posterInfo($poster);
        if (isset($posterInfo['error'])) return redirect()->to('/control/assets')->with('error', $posterInfo['error']);

        $newPosterPath = null;
        $oldPosterPath = $this->resolveStoredAssetPath((string) $asset->poster_storage_key);
        try {
            if ($posterInfo !== []) {
                $posterDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'posters';
                if (! is_dir($posterDir) && ! mkdir($posterDir, 0775, true) && ! is_dir($posterDir)) throw new RuntimeException('Poster storage directory could not be created.');
                $storedName = $asset->public_id . '-' . bin2hex(random_bytes(6)) . '.' . $posterInfo['extension'];
                $poster->move($posterDir, $storedName);
                $newPosterPath = $posterDir . DIRECTORY_SEPARATOR . $storedName;
                $metadata['poster_storage_key'] = 'posters/' . $storedName;
                $metadata['poster_filename'] = $posterInfo['filename'];
                $metadata['poster_mime_type'] = $posterInfo['mime_type'];
            }
            if (! (new AssetModel())->update($asset->id, $metadata)) throw new RuntimeException('Metadata could not be saved.');
            Database::connect()->table('device_assets')->where('asset_id', $asset->id)->update(['title' => $metadata['title']]);
        } catch (Throwable $exception) {
            if ($newPosterPath !== null && is_file($newPosterPath)) @unlink($newPosterPath);
            log_message('error', 'Asset metadata update failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/assets')->with('error', 'Film metadata could not be updated.');
        }
        if ($newPosterPath !== null && $oldPosterPath !== null && is_file($oldPosterPath)) @unlink($oldPosterPath);
        return redirect()->to('/control/assets')->with('success', 'Film metadata updated.');
    }

    public function poster(string $publicId): ResponseInterface
    {
        $asset = $this->accessibleAsset($publicId);
        if ($asset === null || $asset->poster_storage_key === null) return $this->response->setStatusCode(404);
        $path = $this->resolveStoredAssetPath((string) $asset->poster_storage_key);
        if ($path === null) return $this->response->setStatusCode(404);
        return $this->response->download($path, null)
            ->setFileName((string) ($asset->poster_filename ?: basename($path)))
            ->inline()
            ->setHeader('Cache-Control', 'private, max-age=3600')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }

    public function assign(string $publicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $asset = (new AssetModel())->where('public_id', $publicId)->where('status', 'active')->first();
        $device = (new DeviceModel())->where('public_id', trim((string) $this->request->getPost('device_id')))->where('status', 'active')->first();
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Choose an active asset and Player.');
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
        return redirect()->to('/control/assets')->with('success', 'Asset assigned. The Player will begin downloading it after the next heartbeat.');
    }

    public function approve(string $publicId): RedirectResponse
    {
        (new AssetExpiryService())->expireDue();
        $model = new AssetModel();
        $asset = $model->where('public_id', $publicId)->first();
        if ($asset === null) return redirect()->to('/control/assets')->with('error', 'Asset was not found.');
        if (! in_array($asset->status, ['draft', 'rejected'], true)) {
            return redirect()->to('/control/assets')->with('error', 'Only Draft or Rejected assets can be approved.');
        }
        if (! $model->update($asset->id, [
            'status' => 'active', 'reviewed_by' => $this->currentUser()->id,
            'reviewed_at' => gmdate('Y-m-d H:i:s'), 'rejection_reason' => null,
        ])) return redirect()->to('/control/assets')->with('error', 'The asset could not be approved.');
        return redirect()->to('/control/assets')->with('success', 'Film approved. It can now be assigned to a Player.');
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
        if (! $model->update($asset->id, [
            'status' => 'rejected', 'reviewed_by' => $this->currentUser()->id,
            'reviewed_at' => gmdate('Y-m-d H:i:s'), 'rejection_reason' => $reason,
        ])) return redirect()->to('/control/assets')->with('error', 'The asset could not be rejected.');
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

        $originalPath = $this->resolveStoredAssetPath((string) $asset->storage_key);
        $posterPath = $this->resolveStoredAssetPath((string) $asset->poster_storage_key);
        $stagedPath = null;
        $transactionStarted = false;
        try {
            if ($originalPath !== null) {
                $stagingDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . '.delete-staging';
                if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
                    throw new RuntimeException('The deletion staging directory could not be created.');
                }
                $stagedPath = $stagingDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '-' . basename($originalPath);
                if (! rename($originalPath, $stagedPath)) throw new RuntimeException('The asset file could not be staged for deletion.');
            }

            $db->transBegin();
            $transactionStarted = true;
            if (! $assetModel->delete($asset->id) || $db->transStatus() === false) throw new RuntimeException('The asset database record could not be deleted.');
            $db->transCommit();
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if ($transactionStarted) $db->transRollback();
            if ($stagedPath !== null && is_file($stagedPath) && $originalPath !== null) @rename($stagedPath, $originalPath);
            log_message('error', 'Asset deletion failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/assets')->with('error', 'The asset could not be deleted safely.');
        }

        if ($stagedPath !== null && is_file($stagedPath) && ! @unlink($stagedPath)) {
            log_message('error', 'Deleted asset remains in staging: {path}', ['path' => $stagedPath]);
            return redirect()->to('/control/assets')->with('error', 'The database record was deleted, but the staged file still requires manual cleanup.');
        }
        if ($posterPath !== null && is_file($posterPath) && ! @unlink($posterPath)) {
            log_message('error', 'Deleted asset poster remains on disk: {path}', ['path' => $posterPath]);
        }
        return redirect()->to('/control/assets')->with('success', 'Asset file and database record permanently deleted.');
    }

    private function resolveStoredAssetPath(string $storageKey): ?string
    {
        if ($storageKey === '') return null;
        $root = realpath(WRITEPATH . 'uploads');
        $candidate = realpath(WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storageKey));
        if ($root === false || $candidate === false || ! str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) || ! is_file($candidate)) return null;
        return $candidate;
    }

    private function accessibleAsset(string $publicId): ?object
    {
        $currentUser = $this->currentUser();
        $query = (new AssetModel())->where('public_id', $publicId);
        if ($currentUser->role !== 'admin') $query->where('created_by', $currentUser->id);
        return $query->first();
    }

    /** @return array<string, mixed> */
    private function metadataInput(string $fallbackTitle): array
    {
        $text = static fn ($value): ?string => trim((string) $value) !== '' ? trim((string) $value) : null;
        $year = trim((string) $this->request->getPost('production_year'));
        return [
            'title' => trim((string) $this->request->getPost('title')) ?: $fallbackTitle,
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

    private function bumpDeviceAssetRevision(int $deviceId): void
    {
        $updated = Database::connect()->table('devices')->where('id', $deviceId)
            ->set('asset_revision', 'asset_revision + 1', false)
            ->set('updated_at', gmdate('Y-m-d H:i:s'))->update();
        if (! $updated) throw new RuntimeException('Player asset revision could not be incremented.');
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
