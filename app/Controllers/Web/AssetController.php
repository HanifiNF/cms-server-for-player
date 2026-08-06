<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\AssetModel;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\UserModel;
use App\Libraries\MediaMetadataService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use RuntimeException;
use Throwable;

class AssetController extends BaseController
{
    private const EXTENSIONS = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v', 'mpg', 'mpeg', 'ts'];

    public function index(): string
    {
        $assets = (new AssetModel())->where('status', 'active')->orderBy('created_at', 'DESC')->findAll();
        $devices = (new DeviceModel())->where('status', 'active')->orderBy('name')->findAll();
        $assignments = [];
        $deviceNames = [];
        foreach ((new DeviceModel())->findAll() as $device) $deviceNames[(int) $device->id] = ['name' => $device->name, 'public_id' => $device->public_id];
        foreach ((new DeviceAssetModel())->where('asset_id !=', null)->findAll() as $row) {
            $assignments[(int) $row->asset_id][] = [
                'device_id' => (int) $row->device_id,
                'device_name' => $deviceNames[(int) $row->device_id]['name'] ?? 'Unknown Player',
                'device_public_id' => $deviceNames[(int) $row->device_id]['public_id'] ?? '',
                'status' => $row->status,
            ];
        }
        return view('web/assets', [
            'title' => 'Assets', 'active' => 'assets', 'admin' => $this->admin(),
            'assets' => $assets, 'devices' => $devices, 'assignments' => $assignments,
        ]);
    }

    public function upload(): ResponseInterface
    {
        $file = $this->request->getFile('media');
        $title = trim((string) $this->request->getPost('title'));
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            return $this->uploadFailure('Choose a media file. Check PHP upload_max_filesize and post_max_size when uploading large films.');
        }
        $filename = basename($file->getClientName());
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! in_array($extension, self::EXTENSIONS, true)) return $this->uploadFailure('The selected file type is not supported.');
        if ($title === '') $title = pathinfo($filename, PATHINFO_FILENAME);
        if (mb_strlen($title) > 255 || mb_strlen($filename) > 255) return $this->uploadFailure('The title or filename is too long.');

        $publicId = $this->uuidV4();
        $storageDir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'assets';
        $storedName = $publicId . '.' . $extension;
        $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storedName;
        $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
        try {
            if (! is_dir($storageDir) && ! mkdir($storageDir, 0775, true) && ! is_dir($storageDir)) throw new \RuntimeException('Asset storage directory could not be created.');
            $file->move($storageDir, $storedName);
            $size = filesize($storedPath);
            $sha256 = hash_file('sha256', $storedPath);
            if ($size === false || $size <= 0 || $sha256 === false) throw new \RuntimeException('Uploaded media could not be inspected.');
            $durationMs = (new MediaMetadataService())->detectDurationMs($storedPath);
            $inserted = (new AssetModel())->insert([
                'public_id' => $publicId, 'title' => $title, 'filename' => $filename,
                'storage_key' => 'assets/' . $storedName, 'mime_type' => $mimeType,
                'size_bytes' => $size, 'sha256' => $sha256,
                'duration_ms' => $durationMs, 'status' => 'active',
                'created_by' => (int) session()->get('cms_web_user_id'),
            ], true);
            if ($inserted === false) throw new \RuntimeException('Asset metadata could not be stored.');
        } catch (Throwable $exception) {
            if (is_file($storedPath)) @unlink($storedPath);
            log_message('error', 'Asset upload failed: {message}', ['message' => $exception->getMessage()]);
            return $this->uploadFailure('The media asset could not be uploaded.', 500);
        }
        $message = $durationMs > 0
            ? 'Asset uploaded with automatically detected duration. Assign it to a Player to start remote download.'
            : 'Asset uploaded. Duration is pending and will be detected by the first Player that downloads it.';
        if ($this->request->isAJAX()) {
            return $this->response->setStatusCode(201)->setJSON([
                'data' => ['asset_id' => $publicId, 'message' => $message],
            ]);
        }
        return redirect()->to('/control/assets')->with('success', $message);
    }

    public function assign(string $publicId): RedirectResponse
    {
        $asset = (new AssetModel())->where('public_id', $publicId)->where('status', 'active')->first();
        $device = (new DeviceModel())->where('public_id', trim((string) $this->request->getPost('device_id')))->where('status', 'active')->first();
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Choose an active asset and Player.');
        $model = new DeviceAssetModel();
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
        $saved = $existing ? $model->update($existing->id, $values) : $model->insert($values, false);
        if ($saved === false) return redirect()->to('/control/assets')->with('error', 'The asset assignment could not be saved.');
        return redirect()->to('/control/assets')->with('success', 'Asset assigned. Refresh the Player to begin downloading it.');
    }

    public function unassign(string $publicId, string $devicePublicId): RedirectResponse
    {
        $asset = (new AssetModel())->where('public_id', $publicId)->first();
        $device = (new DeviceModel())->where('public_id', $devicePublicId)->first();
        if ($asset === null || $device === null) return redirect()->to('/control/assets')->with('error', 'Asset assignment was not found.');
        $model = new DeviceAssetModel();
        $assignment = $model->where('device_id', $device->id)->where('asset_id', $asset->id)->first();
        if ($assignment !== null) $model->delete($assignment->id);
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
        if (! $model->update($assignment->id, ['status' => 'removal_pending', 'last_reported_at' => gmdate('Y-m-d H:i:s')])) {
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

    private function admin(): object
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
