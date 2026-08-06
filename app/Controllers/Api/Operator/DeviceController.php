<?php

namespace App\Controllers\Api\Operator;

use App\Controllers\BaseController;
use App\Entities\Device;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\OperatorAuthException;
use App\Libraries\OperatorAuthService;
use App\Models\DeviceModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
use Throwable;

class DeviceController extends BaseController
{
    public function available(): ResponseInterface
    {
        try {
            $auth = (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'), ['operator']);
        } catch (OperatorAuthException $exception) {
            return $this->authError($exception);
        }

        $devices = (new DeviceModel())
            ->where('status', 'pending')
            ->where('assigned_user_id', $auth['user']->id)
            ->orderBy('name', 'ASC')->findAll();
        return $this->response->setJSON(['data' => array_map(fn (Device $device) => $this->serialize($device), $devices)]);
    }

    public function controlAccess(string $publicId): ResponseInterface
    {
        try {
            $auth = (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'), ['operator']);
        } catch (OperatorAuthException $exception) {
            return $this->authError($exception);
        }

        $device = (new DeviceModel())->where('public_id', $publicId)->where('status', 'active')->first();
        if ($device === null || $device->assigned_user_id === null || (int) $device->assigned_user_id !== (int) $auth['user']->id) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => ['code' => 'device_control_forbidden', 'message' => 'This operator is not assigned to this Player.'],
            ]);
        }

        return $this->response->setJSON(['data' => [
            'authorized' => true, 'device_id' => $device->public_id, 'device_name' => $device->name,
        ]]);
    }

    public function create(): ResponseInterface
    {
        try {
            (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'), ['admin']);
        } catch (OperatorAuthException $exception) {
            return $this->authError($exception);
        }

        $input = $this->request->getJSON(true) ?? [];
        $name = trim((string) ($input['name'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Jakarta'));
        $location = trim((string) ($input['location'] ?? '')) ?: null;
        $assignedUserId = isset($input['assigned_user_id']) ? (int) $input['assigned_user_id'] : null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'Name is required and must not exceed 120 characters.';
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors['timezone'] = 'Timezone is invalid.';
        if ($location !== null && mb_strlen($location) > 160) $errors['location'] = 'Location must not exceed 160 characters.';
        if ($assignedUserId !== null && (new UserModel())->where('id', $assignedUserId)->where('role', 'operator')->where('status', 'active')->first() === null) $errors['assigned_user_id'] = 'Assigned active operator was not found.';
        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setJSON(['error' => ['code' => 'validation_failed', 'message' => 'The request data is invalid.', 'fields' => $errors]]);
        }

        try {
            $device = (new DeviceEnrollmentService())->createAssignableDevice($name, $timezone, $location, $assignedUserId);
            return $this->response->setStatusCode(201)->setJSON(['data' => $this->serialize($device)]);
        } catch (Throwable $exception) {
            log_message('error', 'Device creation failed: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => ['code' => 'device_creation_failed', 'message' => 'The device could not be created.']]);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Device $device): array
    {
        return [
            'id' => $device->public_id, 'name' => $device->name, 'location' => $device->location,
            'timezone' => $device->timezone, 'assigned_user_id' => $device->assigned_user_id,
        ];
    }

    private function authError(OperatorAuthException $exception): ResponseInterface
    {
        return $this->response->setStatusCode($exception->httpStatus)->setJSON(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]]);
    }
}
