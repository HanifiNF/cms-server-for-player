<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\LocationService;
use App\Libraries\RealtimeOutboxService;
use App\Models\DeviceAssetModel;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Database;
use DateTimeZone;
use RuntimeException;
use Throwable;

class LocationController extends BaseController
{
    public function index(): string
    {
        $query = mb_strtolower(trim((string) $this->request->getGet('q')));
        $status = trim((string) $this->request->getGet('status'));
        if (! in_array($status, ['', 'active', 'inactive'], true)) $status = '';
        $locations = (new LocationModel())->orderBy('name')->findAll();
        $devices = (new DeviceModel())->orderBy('name')->findAll();
        $connection = new DeviceEnrollmentService();
        $byLocation = [];
        foreach ($devices as $device) {
            if ($device->location_id === null) continue;
            $byLocation[(int) $device->location_id][] = [
                'entity' => $device,
                'connection' => $device->status === 'active' ? $connection->connectionStatus($device) : $device->status,
            ];
        }
        $items = [];
        foreach ($locations as $location) {
            if ($status !== '' && $location->status !== $status) continue;
            if ($query !== '' && ! str_contains(mb_strtolower($location->name . ' ' . $location->code . ' ' . $location->address), $query)) continue;
            $studios = $byLocation[(int) $location->id] ?? [];
            $items[] = [
                'entity' => $location,
                'studios' => $studios,
                'total' => count($studios),
                'online' => count(array_filter($studios, static fn (array $item): bool => $item['connection'] === 'online')),
                'offline' => count(array_filter($studios, static fn (array $item): bool => $item['connection'] === 'offline')),
                'playing' => count(array_filter($studios, static fn (array $item): bool => $item['entity']->playback_state === 'playing')),
                'errors' => count(array_filter($studios, static fn (array $item): bool => $item['entity']->playback_state === 'error')),
            ];
        }

        return view('web/locations', [
            'title' => 'Locations', 'active' => 'locations', 'admin' => $this->admin(),
            'locations' => $items, 'filters' => ['q' => (string) $this->request->getGet('q'), 'status' => $status],
        ]);
    }

    public function create(): RedirectResponse
    {
        try {
            (new LocationService())->create($this->request->getPost());
            return redirect()->to('/control/locations')->with('success', 'Location created. Studios can now be assigned to it.');
        } catch (Throwable $error) {
            return redirect()->back()->withInput()->with('error', $error->getMessage());
        }
    }

    public function show(string $publicId): string|RedirectResponse
    {
        $location = $this->location($publicId);
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');

        $operators = (new UserModel())->where('role', 'operator')->where('status', 'active')->orderBy('name')->findAll();
        $operatorNames = [];
        foreach ((new UserModel())->where('role', 'operator')->findAll() as $operator) $operatorNames[(int) $operator->id] = $operator->name;
        $assignmentCounts = [];
        foreach ((new DeviceModel())->select('assigned_user_id, COUNT(*) AS studio_count')->where('assigned_user_id IS NOT NULL')->groupBy('assigned_user_id')->findAll() as $row) {
            $assignmentCounts[(int) $row->assigned_user_id] = (int) $row->studio_count;
        }
        $assetCounts = [];
        foreach ((new DeviceAssetModel())->select('device_id, COUNT(*) AS asset_count')->groupBy('device_id')->findAll() as $row) {
            $assetCounts[(int) $row->device_id] = (int) $row->asset_count;
        }
        $connection = new DeviceEnrollmentService();
        $studios = [];
        foreach ((new DeviceModel())->where('location_id', $location->id)->orderBy('name')->findAll() as $device) {
            $studios[] = [
                'entity' => $device,
                'connection' => $device->status === 'active' ? $connection->connectionStatus($device) : $device->status,
                'operatorName' => $operatorNames[(int) $device->assigned_user_id] ?? 'Unassigned',
                'assetCount' => $assetCounts[(int) $device->id] ?? 0,
            ];
        }

        return view('web/location_detail', [
            'title' => $location->name, 'active' => 'locations', 'admin' => $this->admin(),
            'location' => $location, 'studios' => $studios, 'operators' => $operators,
            'assignmentCounts' => $assignmentCounts,
            'availableLocations' => (new LocationModel())->where('status', 'active')->orderBy('name')->findAll(),
        ]);
    }

    public function createStudio(string $publicId): RedirectResponse
    {
        $location = $this->location($publicId);
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        if ($location->status !== 'active') return $this->back($publicId, 'Inactive Locations cannot receive new Studios.');
        $name = trim((string) $this->request->getPost('name'));
        $timezone = trim((string) $this->request->getPost('timezone')) ?: $location->timezone;
        $assignedId = $this->operatorId($this->request->getPost('assigned_user_id'));
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Studio name is required and must not exceed 120 characters.');
        if (! $this->validTimezone($timezone)) return $this->back($publicId, 'Timezone is invalid.');
        if ($assignedId === false) return $this->back($publicId, 'Choose an active operator.');

        try {
            $device = (new DeviceEnrollmentService())->createAssignableDevice($name, $timezone, $location->name, $assignedId, (int) $location->id);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.created');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio created. It is ready to be paired from its Player PC.');
        } catch (Throwable $error) {
            log_message('error', 'Location Studio creation failed: {message}', ['message' => $error->getMessage()]);
            return $this->back($publicId, 'The Studio could not be created.');
        }
    }

    public function updateStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if ($device->status === 'revoked') return $this->back($publicId, 'A revoked Studio cannot be edited.');
        $name = trim((string) $this->request->getPost('name'));
        $timezone = trim((string) $this->request->getPost('timezone'));
        $targetPublicId = trim((string) $this->request->getPost('location_id')) ?: $publicId;
        $target = (new LocationModel())->where('public_id', $targetPublicId)->where('status', 'active')->first();
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Studio name is required and must not exceed 120 characters.');
        if ($target === null) return $this->back($publicId, 'Choose an active Location.');
        $timezone = $timezone ?: $target->timezone;
        if (! $this->validTimezone($timezone)) return $this->back($publicId, 'Timezone is invalid.');

        try {
            if (! (new DeviceModel())->update($device->id, ['name' => $name, 'location_id' => $target->id, 'location' => $target->name, 'timezone' => $timezone])) throw new RuntimeException('Update failed.');
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.updated');
            return redirect()->to($this->detailUrl($target->public_id))->with('success', 'Studio details updated.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio details could not be updated.');
        }
    }

    public function assignStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'active'], true)) return $this->back($publicId, 'A revoked Studio cannot be assigned. Reset its pairing first.');
        $assignedId = $this->operatorId($this->request->getPost('assigned_user_id'));
        if ($assignedId === false) return $this->back($publicId, 'Choose an active operator.');
        try {
            if (! (new DeviceModel())->update($device->id, ['assigned_user_id' => $assignedId])) throw new RuntimeException('Update failed.');
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.assignment.changed');
            return redirect()->to($this->detailUrl($publicId))->with('success', $assignedId === null ? 'Studio is now unassigned.' : 'Studio operator updated.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio assignment could not be updated.');
        }
    }

    public function createOperator(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'active'], true)) return $this->back($publicId, 'A revoked Studio cannot be assigned.');
        $name = trim((string) $this->request->getPost('name'));
        $email = mb_strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $confirmation = (string) $this->request->getPost('password_confirmation');
        if ($name === '' || mb_strlen($name) > 120) return $this->back($publicId, 'Operator name is required and must not exceed 120 characters.');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->back($publicId, 'Enter a valid operator email address.');
        if (mb_strlen($password) < 12) return $this->back($publicId, 'Operator password must contain at least 12 characters.');
        if ($password !== $confirmation) return $this->back($publicId, 'Operator password confirmation does not match.');
        if ((new UserModel())->where('email', $email)->first() !== null) return $this->back($publicId, 'That email address is already registered.');

        $db = Database::connect();
        try {
            $db->transStart();
            $userId = (new UserModel())->insert(['name' => $name, 'email' => $email, 'role' => 'operator', 'status' => 'active', 'password_hash' => password_hash($password, PASSWORD_ARGON2ID)], true);
            if ($userId === false || ! (new DeviceModel())->update($device->id, ['assigned_user_id' => $userId])) throw new RuntimeException('Create failed.');
            (new RealtimeOutboxService($db))->queueDevice((int) $device->id, 'studio.assignment.changed', ['operator_created' => true]);
            $db->transComplete();
            if (! $db->transStatus()) throw new RuntimeException('Transaction failed.');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Operator created and assigned. Pairing still happens from the Player PC.');
        } catch (Throwable $error) {
            $db->transRollback();
            return $this->back($publicId, 'The operator could not be created and assigned.');
        }
    }

    public function resetStudioPairing(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['active', 'revoked'], true)) return $this->back($publicId, 'This Studio is already waiting for pairing.');
        try {
            (new DeviceEnrollmentService())->resetPairing($device);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.pairing.reset');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Pairing reset. The assigned operator can pair this Studio from a Player PC.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'Studio pairing could not be reset.');
        }
    }

    public function revokeStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if ($device->status !== 'active') return $this->back($publicId, 'Only an active Studio can be revoked.');
        try {
            (new DeviceEnrollmentService())->revoke($device);
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.revoked');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio revoked. Its Player will return to pairing when it contacts the CMS.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'The Studio could not be revoked.');
        }
    }

    public function deleteStudio(string $publicId, string $devicePublicId): RedirectResponse
    {
        $device = $this->studio($publicId, $devicePublicId);
        if ($device === null) return $this->back($publicId, 'Studio was not found in this Location.');
        if (! in_array($device->status, ['pending', 'revoked'], true)) return $this->back($publicId, 'Reset or revoke an active Studio before deleting it.');
        try {
            (new RealtimeOutboxService())->queueDevice((int) $device->id, 'studio.deleted');
            if (! (new DeviceModel())->delete($device->id)) throw new RuntimeException('Delete failed.');
            return redirect()->to($this->detailUrl($publicId))->with('success', 'Studio permanently deleted.');
        } catch (Throwable $error) {
            return $this->back($publicId, 'The Studio could not be deleted.');
        }
    }

    public function update(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->update($location, $this->request->getPost());
            return redirect()->to('/control/locations')->with('success', 'Location updated. Connected Players will receive the new name on heartbeat.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage());
        }
    }

    public function status(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->setStatus($location, (string) $this->request->getPost('status'));
            return redirect()->to('/control/locations')->with('success', 'Location status updated.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage());
        }
    }

    public function delete(string $publicId): RedirectResponse
    {
        $location = (new LocationModel())->where('public_id', $publicId)->first();
        if ($location === null) return redirect()->to('/control/locations')->with('error', 'Location was not found.');
        try {
            (new LocationService())->delete($location);
            return redirect()->to('/control/locations')->with('success', 'Unused Location permanently deleted.');
        } catch (Throwable $error) {
            return redirect()->to('/control/locations')->with('error', $error->getMessage());
        }
    }

    private function admin(): object
    {
        return (new UserModel())->find((int) session()->get('cms_web_user_id'));
    }

    private function location(string $publicId): ?object
    {
        return (new LocationModel())->where('public_id', $publicId)->first();
    }

    private function studio(string $locationPublicId, string $devicePublicId): ?object
    {
        $location = $this->location($locationPublicId);
        if ($location === null) return null;
        return (new DeviceModel())->where('public_id', $devicePublicId)->where('location_id', $location->id)->first();
    }

    private function operatorId(mixed $value): int|null|false
    {
        $id = (int) $value;
        if ($id <= 0) return null;
        return (new UserModel())->where('id', $id)->where('role', 'operator')->where('status', 'active')->first() === null ? false : $id;
    }

    private function validTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function detailUrl(string $publicId): string
    {
        return '/control/locations/' . rawurlencode($publicId);
    }

    private function back(string $publicId, string $error): RedirectResponse
    {
        return redirect()->to($this->detailUrl($publicId))->with('error', $error);
    }
}
