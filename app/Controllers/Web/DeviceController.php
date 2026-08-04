<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Models\DeviceModel;
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
        $allDevices = array_map(static function ($device) use ($userNames, $offlineAfter, $now): array {
            $connection = $device->status;
            if ($device->status === 'active') {
                $lastSeen = $device->last_seen_at ? $device->last_seen_at->getTimestamp() : 0;
                $connection = $lastSeen > 0 && ($now - $lastSeen) <= $offlineAfter ? 'online' : 'offline';
            }
            return ['entity' => $device, 'connection' => $connection, 'assignedName' => $userNames[(int) $device->assigned_user_id] ?? 'Unassigned'];
        }, (new DeviceModel())->orderBy('created_at', 'DESC')->findAll());

        return view('web/devices', [
            'title' => 'Players', 'active' => 'devices', 'admin' => $this->admin(),
            'devices' => array_values(array_filter($allDevices, static fn (array $item): bool => $item['entity']->status !== 'revoked')),
            'revokedDevices' => array_values(array_filter($allDevices, static fn (array $item): bool => $item['entity']->status === 'revoked')),
            'operators' => $users,
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
        if ($device->status !== 'pending') return redirect()->to('/control/devices')->with('error', 'Only pending Players can be reassigned.');
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
