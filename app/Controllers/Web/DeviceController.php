<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\CollectionPage;
use App\Libraries\LocationService;
use App\Models\DeviceModel;
use App\Models\DeviceAssetModel;
use App\Models\UserModel;
use App\Models\LocationModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Player;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class DeviceController extends BaseController
{
    public function index(): RedirectResponse
    {
        return redirect()->to('/control/locations');
    }

    public function assets(string $publicId): string|RedirectResponse
    {
        $device = (new DeviceModel())->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Studio was not found.');

        $totals = Database::connect()->table('device_assets')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) AS ready", false)
            ->select("SUM(CASE WHEN status = 'missing' THEN 1 ELSE 0 END) AS missing", false)
            ->select("SUM(CASE WHEN status <> 'ready' THEN 1 ELSE 0 END) AS problems", false)
            ->select('MAX(last_reported_at) AS last_synced_at', false)
            ->where('device_id', $device->id)->get()->getRowArray() ?? [];
        $summary = [
            'total' => (int) ($totals['total'] ?? 0), 'ready' => (int) ($totals['ready'] ?? 0),
            'missing' => (int) ($totals['missing'] ?? 0), 'problems' => (int) ($totals['problems'] ?? 0),
        ];
        $lastSyncedAt = empty($totals['last_synced_at']) ? null : new DateTimeImmutable((string) $totals['last_synced_at']);

        $filters = $this->assetFilters();
        $resultCount = (clone $this->filteredAssets((int) $device->id, $filters))->countAllResults();

        return view('web/device_assets', [
            'title' => 'Studio Assets', 'active' => 'locations', 'admin' => $this->admin(),
            'device' => $device, 'assets' => [],
            'locationPublicId' => $device->location_id === null ? null : (new LocationModel())->find((int) $device->location_id)?->public_id,
            'summary' => $summary, 'lastSyncedAt' => $lastSyncedAt,
            'filters' => $filters, 'resultCount' => $resultCount,
        ]);
    }

    public function assetCollection(string $publicId): ResponseInterface
    {
        $device = (new DeviceModel())->where('public_id', $publicId)->first();
        if ($device === null) return $this->response->setStatusCode(404)->setJSON(['error' => ['message' => 'Studio was not found.']]);
        $filters = $this->assetFilters();
        $total = $this->filteredAssets((int) $device->id, $filters)->countAllResults();
        $page = CollectionPage::fromQuery((array) $this->request->getGet(), $total, 50, 100);
        $query = $this->filteredAssets((int) $device->id, $filters);
        $assets = $query->orderBy('title')->orderBy('relative_path')->findAll($page->perPage(), $page->offset());
        $formatBytes = static function (int $bytes): string {
            if ($bytes <= 0) return '0 B';
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
            return number_format($bytes / (1024 ** $power), $power >= 3 ? 1 : 0) . ' ' . $units[$power];
        };
        $formatDuration = static function (int $milliseconds): string {
            if ($milliseconds <= 0) return 'Unknown';
            $seconds = (int) floor($milliseconds / 1000);
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        };
        $items = array_map(static fn (object $asset): array => [
            'id' => (int) $asset->id,
            'html' => view('web/_device_asset_row', ['asset' => $asset, 'formatBytes' => $formatBytes, 'formatDuration' => $formatDuration]),
        ], $assets);
        return $this->response->setJSON(['data' => $page->payload($items)]);
    }

    public function create(): RedirectResponse
    {
        $name = trim((string) $this->request->getPost('name'));
        $locationPublicId = trim((string) $this->request->getPost('location_id')) ?: null;
        $legacyLocation = trim((string) $this->request->getPost('location')) ?: null;
        $timezone = trim((string) $this->request->getPost('timezone'));
        $assignedId = (int) $this->request->getPost('assigned_user_id');
        $assignedId = $assignedId > 0 ? $assignedId : null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'Name is required and must not exceed 120 characters.';
        if ($locationPublicId === null && $legacyLocation === null) $errors['location_id'] = 'Choose an active Location.';
        if ($legacyLocation !== null && mb_strlen($legacyLocation) > 160) $errors['location'] = 'Location must not exceed 160 characters.';
        if ($timezone !== '' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = 'Timezone is invalid.';
        if ($assignedId !== null && ! $this->validOperator($assignedId)) $errors['assigned_user_id'] = 'Choose an active operator.';
        if ($errors !== []) return redirect()->back()->withInput()->with('errors', $errors);

        try {
            $location = (new LocationService())->findSelection($locationPublicId, $legacyLocation, $timezone ?: 'Asia/Jakarta');
            if ($location === null || $location->status !== 'active') return redirect()->back()->withInput()->with('error', 'Choose an active Location.');
            (new DeviceEnrollmentService())->createAssignableDevice(
                $name, $timezone ?: $location->timezone, $location->name, $assignedId, (int) $location->id,
            );
        } catch (Throwable $exception) {
            log_message('error', 'Web device creation failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'The Studio record could not be created.');
        }
        return redirect()->to('/control/devices')->with('success', 'Studio created and ready to be claimed by its Player.');
    }

    public function assignment(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Studio was not found.');
        if (! in_array($device->status, ['pending', 'active'], true)) return redirect()->to('/control/devices')->with('error', 'Revoked Studios cannot be reassigned.');
        $assignedId = (int) $this->request->getPost('assigned_user_id');
        $assignedId = $assignedId > 0 ? $assignedId : null;
        if ($assignedId !== null && ! $this->validOperator($assignedId)) return redirect()->to('/control/devices')->with('error', 'Choose an active operator.');
        $model->update($device->id, ['assigned_user_id' => $assignedId]);
        return redirect()->to('/control/devices')->with('success', 'Studio assignment updated.');
    }

    public function details(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Studio was not found.');
        if ($device->status === 'revoked') return redirect()->to('/control/devices')->with('error', 'A revoked Studio cannot be edited.');
        $name = trim((string) $this->request->getPost('name'));
        $locationPublicId = trim((string) $this->request->getPost('location_id'));
        $timezone = trim((string) $this->request->getPost('timezone'));
        $location = (new LocationModel())->where('public_id', $locationPublicId)->where('status', 'active')->first();
        if ($name === '' || mb_strlen($name) > 120) return redirect()->to('/control/devices')->with('error', 'Studio name is required and must not exceed 120 characters.');
        if ($location === null) return redirect()->to('/control/devices')->with('error', 'Choose an active Location.');
        if ($timezone !== '' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) return redirect()->to('/control/devices')->with('error', 'Timezone is invalid.');
        if (! $model->update($device->id, [
            'name' => $name, 'location_id' => $location->id, 'location' => $location->name,
            'timezone' => $timezone ?: $location->timezone,
        ])) return redirect()->to('/control/devices')->with('error', 'Studio details could not be updated.');
        return redirect()->to('/control/devices')->with('success', 'Studio details updated. The Player receives them on its next heartbeat.');
    }

    public function revoke(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Studio was not found.');
        if ($device->status !== 'active') return redirect()->to('/control/devices')->with('error', 'Only an active Studio can be revoked.');
        try {
            (new DeviceEnrollmentService())->revoke($device);
        } catch (Throwable $exception) {
            log_message('error', 'Web Player revoke failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/devices')->with('error', 'The Player could not be revoked.');
        }
        return redirect()->to('/control/devices')->with('success', 'Studio revoked. Its Player will return to pairing when it contacts the CMS.');
    }

    public function delete(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Studio was not found.');
        if ($device->status !== 'revoked') return redirect()->to('/control/devices')->with('error', 'Only a revoked Studio can be permanently deleted.');
        try {
            if (! $model->delete($device->id)) throw new \RuntimeException('Delete failed.');
        } catch (Throwable $exception) {
            log_message('error', 'Web Player delete failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/devices')->with('error', 'The revoked Player could not be deleted.');
        }
        return redirect()->to('/control/devices')->with('success', 'Revoked Studio permanently deleted.');
    }

    private function validOperator(int $id): bool
    {
        return (new UserModel())->where('id', $id)->where('role', 'operator')->where('status', 'active')->first() !== null;
    }

    /** @return array{q:string,status:string,source:string} */
    private function assetFilters(): array
    {
        $status = trim((string) $this->request->getGet('status'));
        $source = trim((string) $this->request->getGet('source'));
        if (! in_array($status, ['', 'ready', 'missing', 'corrupt', 'unreadable'], true)) $status = '';
        if (! in_array($source, ['', 'local', 'managed'], true)) $source = '';
        return ['q' => mb_substr(trim((string) $this->request->getGet('q')), 0, 120), 'status' => $status, 'source' => $source];
    }

    /** @param array{q:string,status:string,source:string} $filters */
    private function filteredAssets(int $deviceId, array $filters): DeviceAssetModel
    {
        $query = (new DeviceAssetModel())->where('device_id', $deviceId);
        if ($filters['status'] !== '') $query->where('status', $filters['status']);
        if ($filters['source'] !== '') $query->where('source', $filters['source']);
        if ($filters['q'] !== '') $query->groupStart()->like('title', $filters['q'])->orLike('filename', $filters['q'])->orLike('relative_path', $filters['q'])->orLike('media_key', $filters['q'])->groupEnd();
        return $query;
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
