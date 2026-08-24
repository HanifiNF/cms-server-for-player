<?php

namespace App\Controllers\Api\Player;

use App\Controllers\BaseController;
use App\Libraries\AssetInventoryService;
use App\Libraries\AssetExpiryService;
use App\Libraries\LdgCryptoService;
use App\Libraries\StorageManager;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\EnrollmentException;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\HTTP\RangeFileResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use Throwable;

class AssetController extends BaseController
{
    private const MAX_ASSETS = 2000;

    public function sync(): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
        } catch (EnrollmentException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON([
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ]);
        }
        (new AssetExpiryService())->expireDue();

        $input = $this->request->getJSON(true) ?? [];
        $assets = $input['assets'] ?? null;
        if (! is_array($assets) || ! array_is_list($assets)) {
            return $this->validationError(['assets' => 'assets must be a JSON array.']);
        }
        if (count($assets) > self::MAX_ASSETS) {
            return $this->validationError(['assets' => sprintf('A snapshot may contain at most %d assets.', self::MAX_ASSETS)]);
        }

        $normalized = [];
        $seenKeys = [];
        $errors = [];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                $errors["assets.{$index}"] = 'Each asset must be an object.';
                continue;
            }
            $itemErrors = [];
            $item = $this->normalizeAsset($asset, $itemErrors);
            foreach ($itemErrors as $field => $message) {
                $errors["assets.{$index}.{$field}"] = $message;
            }
            if ($item === null) {
                continue;
            }
            if (isset($seenKeys[$item['media_key']])) {
                $errors["assets.{$index}.media_key"] = 'media_key must be unique within the snapshot.';
                continue;
            }
            $seenKeys[$item['media_key']] = true;
            $normalized[] = $item;
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        try {
            $result = (new AssetInventoryService())->sync($device, $normalized);
        } catch (Throwable $exception) {
            log_message('error', 'Player asset sync failed: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'asset_sync_failed', 'message' => 'The Player asset inventory could not be synchronized.'],
            ]);
        }

        return $this->response->setJSON(['data' => $result]);
    }

    public function assigned(): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
        } catch (EnrollmentException $exception) {
            return $this->authenticationError($exception);
        }
        (new AssetExpiryService())->expireDue();

        $assignments = (new DeviceAssetModel())->where('device_id', $device->id)->where('asset_id !=', null)->findAll();
        $assets = [];
        $assetModel = new AssetModel();
        $ldg = new LdgCryptoService();
        $playerToken = $this->bearerToken();
        foreach ($assignments as $assignment) {
            if ($assignment->status === 'removal_pending') continue;
            $asset = $assetModel->where('id', $assignment->asset_id)->where('status', 'active')->first();
            if ($asset === null) continue;
            $encrypted = (string) ($asset->encryption_format ?? '') === LdgCryptoService::FORMAT;
            if ($encrypted && (string) ($device->ldg_version ?? '') !== LdgCryptoService::FORMAT) continue;
            $crypto = null;
            if ($encrypted) {
                try {
                    $crypto = [
                        'format' => LdgCryptoService::FORMAT,
                        'header_size' => LdgCryptoService::HEADER_SIZE,
                        'chunk_size' => (int) $asset->ldg_chunk_size,
                        'plaintext_size' => (int) $asset->plaintext_size_bytes,
                        'plaintext_sha256' => (string) $asset->plaintext_sha256,
                        'original_mime_type' => (string) $asset->mime_type,
                        'encryption_revision' => max(1, (int) ($asset->encryption_revision ?? $asset->revision)),
                        'license' => $ldg->deviceLicense($asset, (string) $device->public_id, $playerToken),
                    ];
                } catch (\Throwable $error) {
                    log_message('error', 'LDG license delivery failed for {asset}: {message}', [
                        'asset' => $asset->public_id, 'message' => $error->getMessage(),
                    ]);
                    return $this->response->setStatusCode(500)->setJSON([
                        'error' => ['code' => 'asset_license_failed', 'message' => 'The encrypted asset license could not be issued.'],
                    ]);
                }
            }
            $assets[] = [
                'id' => $asset->public_id,
                'title' => $asset->title,
                'filename' => $encrypted ? $ldg->downloadFilename($asset) : $asset->filename,
                'display_filename' => $asset->filename,
                'download_url' => '/api/player/assets/' . rawurlencode($asset->public_id) . '/download',
                'size' => (int) $asset->size_bytes,
                'sha256' => $asset->sha256,
                'mime_type' => $encrypted ? LdgCryptoService::MIME_TYPE : $asset->mime_type,
                'duration_ms' => (int) $asset->duration_ms,
                'revision' => max(1, (int) $asset->revision),
                'encryption' => $crypto,
            ];
        }
        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $assets]);
    }

    public function removals(): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
        } catch (EnrollmentException $exception) {
            return $this->authenticationError($exception);
        }
        (new AssetExpiryService())->expireDue();
        $items = [];
        $assetModel = new AssetModel();
        foreach ((new DeviceAssetModel())->where('device_id', $device->id)->where('status', 'removal_pending')->findAll() as $assignment) {
            if ($assignment->asset_id === null) continue;
            $asset = $assetModel->find($assignment->asset_id);
            if ($asset === null) continue;
            $encrypted = (string) ($asset->encryption_format ?? '') === LdgCryptoService::FORMAT;
            $items[] = [
                'id' => $asset->public_id,
                'filename' => $encrypted ? (new LdgCryptoService())->downloadFilename($asset) : $asset->filename,
                'encryption_format' => $encrypted ? LdgCryptoService::FORMAT : null,
            ];
        }
        return $this->response->setJSON(['data' => $items]);
    }

    public function removed(string $publicId): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
        } catch (EnrollmentException $exception) {
            return $this->authenticationError($exception);
        }
        $asset = (new AssetModel())->where('public_id', $publicId)->first();
        if ($asset === null) return $this->assetNotFound();
        $model = new DeviceAssetModel();
        $assignment = $model->where('device_id', $device->id)->where('asset_id', $asset->id)
            ->where('status', 'removal_pending')->first();
        if ($assignment === null) return $this->assetNotFound();
        if (! $model->delete($assignment->id)) {
            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'removal_ack_failed', 'message' => 'The removal acknowledgment could not be saved.'],
            ]);
        }
        return $this->response->setJSON(['data' => ['removed' => true, 'asset_id' => $publicId]]);
    }

    public function download(string $publicId): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
        } catch (EnrollmentException $exception) {
            return $this->authenticationError($exception);
        }
        (new AssetExpiryService())->expireDue();

        $asset = (new AssetModel())->where('public_id', $publicId)->where('status', 'active')->first();
        if ($asset === null) return $this->assetNotFound();
        $assignment = (new DeviceAssetModel())->where('device_id', $device->id)->where('asset_id', $asset->id)
            ->where('status !=', 'removal_pending')->first();
        if ($assignment === null) return $this->assetNotFound();

        try {
            $storage = new StorageManager();
            $profile = $storage->profile($asset->storage_profile_id === null ? null : (int) $asset->storage_profile_id);
            $filePath = $storage->materialize($profile, (string) $asset->storage_key);
        } catch (Throwable $error) {
            log_message('error', 'Player asset storage resolution failed: {message}', ['message' => $error->getMessage()]);
            $filePath = null;
        }
        if ($filePath === null || ! is_file($filePath)) {
            return $this->assetNotFound();
        }
        $size = filesize($filePath);
        if ($size === false || $size <= 0) return $this->assetNotFound();
        $etag = '"' . strtolower((string) $asset->sha256) . '"';
        $rangeHeader = trim($this->request->getHeaderLine('Range'));
        $ifRange = trim($this->request->getHeaderLine('If-Range'));
        if ($rangeHeader !== '' && $ifRange !== '' && ! hash_equals($etag, $ifRange)) $rangeHeader = '';
        $start = 0;
        $end = $size - 1;
        $partial = false;
        if ($rangeHeader !== '') {
            $range = $this->parseRange($rangeHeader, $size);
            if ($range === null) {
                return $this->response->setStatusCode(416)
                    ->setHeader('Accept-Ranges', 'bytes')->setHeader('Content-Range', "bytes */{$size}")
                    ->setHeader('ETag', $etag)->setBody('');
            }
            [$start, $end] = $range;
            $partial = true;
        }
        $encrypted = (string) ($asset->encryption_format ?? '') === LdgCryptoService::FORMAT;
        return new RangeFileResponse(
            $filePath, $start, $end, $encrypted ? (new LdgCryptoService())->downloadFilename($asset) : (string) $asset->filename,
            $encrypted ? LdgCryptoService::MIME_TYPE : (string) ($asset->mime_type ?: 'application/octet-stream'), $etag, $partial,
        );
    }

    /** @return array{int, int}|null */
    private function parseRange(string $header, int $size): ?array
    {
        if ($size <= 0 || str_contains($header, ',') || ! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) return null;
        if ($matches[1] === '' && $matches[2] === '') return null;
        if ($matches[1] === '') {
            $suffix = (int) $matches[2];
            if ($suffix <= 0) return null;
            return [max(0, $size - $suffix), $size - 1];
        }
        $start = (int) $matches[1];
        if ($start >= $size) return null;
        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        return $end >= $start ? [$start, $end] : null;
    }

    private function bearerToken(): string
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($this->request->getHeaderLine('Authorization')), $matches)) return '';
        return trim($matches[1]);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private function normalizeAsset(array $asset, array &$errors): ?array
    {
        $mediaKey = trim((string) ($asset['media_key'] ?? ''));
        $source = trim((string) ($asset['source'] ?? ''));
        $title = trim((string) ($asset['title'] ?? ''));
        $filename = trim((string) ($asset['filename'] ?? ''));
        $relativePath = str_replace('\\', '/', trim((string) ($asset['relative_path'] ?? '')));
        $status = trim((string) ($asset['status'] ?? ''));
        $sha256 = isset($asset['sha256']) && $asset['sha256'] !== '' ? strtolower(trim((string) $asset['sha256'])) : null;
        $modifiedAt = isset($asset['modified_at']) && $asset['modified_at'] !== '' ? trim((string) $asset['modified_at']) : null;

        if (! preg_match('/^(?:local:[a-f0-9]{64}|managed:[A-Za-z0-9._:-]{1,100})$/', $mediaKey)) $errors['media_key'] = 'media_key has an invalid format.';
        if (! in_array($source, ['local', 'managed'], true)) $errors['source'] = 'source must be local or managed.';
        if (($source === 'local' && ! str_starts_with($mediaKey, 'local:')) || ($source === 'managed' && ! str_starts_with($mediaKey, 'managed:'))) $errors['source'] = 'source must match the media_key namespace.';
        if ($title === '' || mb_strlen($title) > 255) $errors['title'] = 'title is required and must not exceed 255 characters.';
        if ($filename === '' || mb_strlen($filename) > 255) $errors['filename'] = 'filename is required and must not exceed 255 characters.';
        if ($relativePath === '' || mb_strlen($relativePath) > 1000 || $this->isUnsafeRelativePath($relativePath)) $errors['relative_path'] = 'relative_path must be a safe relative path.';
        if (! in_array($status, ['ready', 'missing', 'corrupt', 'unreadable'], true)) $errors['status'] = 'status is invalid.';

        $sizeBytes = filter_var($asset['size_bytes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $durationMs = filter_var($asset['duration_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($sizeBytes === false) $errors['size_bytes'] = 'size_bytes must be a non-negative integer.';
        if ($durationMs === false) $errors['duration_ms'] = 'duration_ms must be a non-negative integer.';
        if ($sha256 !== null && ! preg_match('/^[a-f0-9]{64}$/', $sha256)) $errors['sha256'] = 'sha256 must contain 64 hexadecimal characters.';
        if ($modifiedAt !== null) {
            try {
                $modifiedAt = (new DateTimeImmutable($modifiedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $errors['modified_at'] = 'modified_at must be a valid ISO-8601 timestamp.';
            }
        }

        if ($errors !== []) return null;
        return [
            'media_key' => $mediaKey, 'source' => $source, 'title' => $title,
            'filename' => $filename, 'relative_path' => $relativePath,
            'size_bytes' => (int) $sizeBytes, 'duration_ms' => (int) $durationMs,
            'sha256' => $sha256, 'status' => $status, 'modified_at' => $modifiedAt,
        ];
    }

    private function isUnsafeRelativePath(string $path): bool
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) || str_contains($path, "\0")) return true;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return true;
        }
        return false;
    }

    /** @param array<string, string> $fields */
    private function validationError(array $fields): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'error' => ['code' => 'validation_failed', 'message' => 'The asset snapshot is invalid.', 'fields' => $fields],
        ]);
    }

    private function authenticationError(EnrollmentException $exception): ResponseInterface
    {
        return $this->response->setStatusCode($exception->httpStatus)->setJSON([
            'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
        ]);
    }

    private function assetNotFound(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setJSON([
            'error' => ['code' => 'asset_not_found', 'message' => 'The assigned asset was not found.'],
        ]);
    }
}
