<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Models\DeviceModel;
use App\Models\DeviceAssetModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Player;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

class DeviceController extends BaseController
{
    public function index(): string
    {
        $users = (new UserModel())->where('role', 'operator')->where('status', 'active')->orderBy('name')->findAll();
        $userNames = [];
        foreach ((new UserModel())->findAll() as $user) $userNames[(int) $user->id] = $user->name;
        $offlineAfter = config(Player::class)->offlineAfterSeconds;
        $now = time();
        $assetCounts = [];
        foreach ((new DeviceAssetModel())->select('device_id, COUNT(*) AS asset_count')->groupBy('device_id')->findAll() as $row) {
            $assetCounts[(int) $row->device_id] = (int) $row->asset_count;
        }
        $allDevices = array_map(static function ($device) use ($userNames, $offlineAfter, $now, $assetCounts): array {
            $connection = $device->status;
            if ($device->status === 'active') {
                $lastSeen = $device->last_seen_at ? $device->last_seen_at->getTimestamp() : 0;
                $connection = $lastSeen > 0 && ($now - $lastSeen) <= $offlineAfter ? 'online' : 'offline';
            }
            return [
                'entity' => $device,
                'connection' => $connection,
                'assignedName' => $userNames[(int) $device->assigned_user_id] ?? 'Unassigned',
                'assetCount' => $assetCounts[(int) $device->id] ?? 0,
            ];
        }, (new DeviceModel())->orderBy('created_at', 'DESC')->findAll());

        return view('web/devices', [
            'title' => 'Players', 'active' => 'devices', 'admin' => $this->admin(),
            'devices' => array_values(array_filter($allDevices, static fn (array $item): bool => $item['entity']->status !== 'revoked')),
            'revokedDevices' => array_values(array_filter($allDevices, static fn (array $item): bool => $item['entity']->status === 'revoked')),
            'operators' => $users,
        ]);
    }

    public function assets(string $publicId): string|RedirectResponse
    {
        $device = (new DeviceModel())->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Player was not found.');

        $query = mb_strtolower(trim((string) $this->request->getGet('q')));
        $status = trim((string) $this->request->getGet('status'));
        $source = trim((string) $this->request->getGet('source'));
        if (! in_array($status, ['', 'ready', 'missing', 'corrupt', 'unreadable'], true)) $status = '';
        if (! in_array($source, ['', 'local', 'managed'], true)) $source = '';

        $allAssets = (new DeviceAssetModel())->where('device_id', $device->id)->findAll();
        $summary = ['total' => 0, 'ready' => 0, 'missing' => 0, 'problems' => 0];
        $lastSyncedAt = null;
        foreach ($allAssets as $asset) {
            $summary['total']++;
            if ($asset->status === 'ready') $summary['ready']++;
            else $summary['problems']++;
            if ($asset->status === 'missing') $summary['missing']++;
            if ($asset->last_reported_at !== null && ($lastSyncedAt === null || $asset->last_reported_at->getTimestamp() > $lastSyncedAt->getTimestamp())) {
                $lastSyncedAt = $asset->last_reported_at;
            }
        }

        $assets = array_values(array_filter($allAssets, static function ($asset) use ($query, $status, $source): bool {
            if ($status !== '' && $asset->status !== $status) return false;
            if ($source !== '' && $asset->source !== $source) return false;
            if ($query === '') return true;
            $haystack = mb_strtolower(implode(' ', [$asset->title, $asset->filename, $asset->relative_path, $asset->media_key]));
            return str_contains($haystack, $query);
        }));
        usort($assets, static fn ($left, $right): int => strcasecmp($left->title, $right->title) ?: strcasecmp($left->relative_path ?? '', $right->relative_path ?? ''));

        return view('web/device_assets', [
            'title' => 'Player Assets', 'active' => 'devices', 'admin' => $this->admin(),
            'device' => $device, 'assets' => array_slice($assets, 0, 1000),
            'summary' => $summary, 'lastSyncedAt' => $lastSyncedAt,
            'filters' => ['q' => (string) $this->request->getGet('q'), 'status' => $status, 'source' => $source],
            'resultCount' => count($assets),
        ]);
    }

    public function create(): RedirectResponse
    {
        $name = trim((string) $this->request->getPost('name'));
        $location = trim((string) $this->request->getPost('location')) ?: null;
        $timezone = trim((string) $this->request->getPost('timezone')) ?: 'Asia/Jakarta';
        $assignedId = (int) $this->request->getPost('assigned_user_id');
        $assignedId = $assignedId > 0 ? $assignedId : null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'Name is required and must not exceed 120 characters.';
        if ($location !== null && mb_strlen($location) > 160) $errors['location'] = 'Location must not exceed 160 characters.';
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = 'Timezone is invalid.';
        if ($assignedId !== null && ! $this->validOperator($assignedId)) $errors['assigned_user_id'] = 'Choose an active operator.';
        if ($errors !== []) return redirect()->back()->withInput()->with('errors', $errors);

        try {
            (new DeviceEnrollmentService())->createAssignableDevice($name, $timezone, $location, $assignedId);
        } catch (Throwable $exception) {
            log_message('error', 'Web device creation failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->back()->withInput()->with('error', 'The Player record could not be created.');
        }
        return redirect()->to('/control/devices')->with('success', 'Player created and ready to be claimed.');
    }

    public function assignment(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Player was not found.');
        if (! in_array($device->status, ['pending', 'active'], true)) return redirect()->to('/control/devices')->with('error', 'Revoked Players cannot be reassigned.');
        $assignedId = (int) $this->request->getPost('assigned_user_id');
        $assignedId = $assignedId > 0 ? $assignedId : null;
        if ($assignedId !== null && ! $this->validOperator($assignedId)) return redirect()->to('/control/devices')->with('error', 'Choose an active operator.');
        $model->update($device->id, ['assigned_user_id' => $assignedId]);
        return redirect()->to('/control/devices')->with('success', 'Player assignment updated.');
    }

    public function revoke(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Player was not found.');
        if ($device->status !== 'active') return redirect()->to('/control/devices')->with('error', 'Only an active Player can be revoked.');
        try {
            (new DeviceEnrollmentService())->revoke($device);
        } catch (Throwable $exception) {
            log_message('error', 'Web Player revoke failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/devices')->with('error', 'The Player could not be revoked.');
        }
        return redirect()->to('/control/devices')->with('success', 'Player revoked. It will return to pairing when it contacts the CMS.');
    }

    public function delete(string $publicId): RedirectResponse
    {
        $model = new DeviceModel();
        $device = $model->where('public_id', $publicId)->first();
        if ($device === null) return redirect()->to('/control/devices')->with('error', 'Player was not found.');
        if ($device->status !== 'revoked') return redirect()->to('/control/devices')->with('error', 'Only a revoked Player can be permanently deleted.');
        try {
            if (! $model->delete($device->id)) throw new \RuntimeException('Delete failed.');
        } catch (Throwable $exception) {
            log_message('error', 'Web Player delete failed: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to('/control/devices')->with('error', 'The revoked Player could not be deleted.');
        }
        return redirect()->to('/control/devices')->with('success', 'Revoked Player permanently deleted.');
    }

    private function validOperator(int $id): bool
    {
        return (new UserModel())->where('id', $id)->where('role', 'operator')->where('status', 'active')->first() !== null;
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
