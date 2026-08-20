<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\LocationService;
use App\Models\DeviceModel;
use App\Models\DeviceAssetModel;
use App\Models\UserModel;
use App\Models\LocationModel;
use CodeIgniter\HTTP\RedirectResponse;
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
            'title' => 'Studio Assets', 'active' => 'locations', 'admin' => $this->admin(),
            'device' => $device, 'assets' => array_slice($assets, 0, 1000),
            'locationPublicId' => $device->location_id === null ? null : (new LocationModel())->find((int) $device->location_id)?->public_id,
            'summary' => $summary, 'lastSyncedAt' => $lastSyncedAt,
            'filters' => ['q' => (string) $this->request->getGet('q'), 'status' => $status, 'source' => $source],
            'resultCount' => count($assets),
        ]);
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

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }
}
