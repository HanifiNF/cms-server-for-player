<?php

namespace App\Controllers\Api\Admin;

use App\Controllers\BaseController;
use App\Entities\Device;
use App\Libraries\DeviceEnrollmentService;
use App\Models\DeviceModel;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
use Throwable;
use Config\Player;

class DeviceController extends BaseController
{
    public function enroll(): ResponseInterface
    {
        if (! config(Player::class)->enablePairingCode) {
            return $this->response->setStatusCode(403)->setJSON(['error' => ['code' => 'pairing_code_disabled', 'message' => 'Pairing-code enrollment is disabled.']]);
        }
        $input = $this->request->getJSON(true) ?? [];
        $name = trim((string) ($input['name'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Jakarta'));

        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Name is required and must not exceed 120 characters.';
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Timezone must be a valid IANA timezone identifier.';
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        try {
            $result = (new DeviceEnrollmentService())->createEnrollment($name, $timezone);
        } catch (Throwable $exception) {
            log_message('error', 'Device enrollment failed: {message}', ['message' => $exception->getMessage()]);

            return $this->serverError();
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(201)->setJSON([
            'data' => [
                'device'          => $this->serializeDevice($result['device']),
                'enrollment_code' => $result['enrollment_code'],
                'expires_at'      => $result['expires_at'],
            ],
        ]);
    }

    public function index(): ResponseInterface
    {
        $model = new DeviceModel();
        $service = new DeviceEnrollmentService($model);
        $devices = $model->orderBy('created_at', 'DESC')->findAll();

        return $this->response->setJSON([
            'data' => array_map(
                fn (Device $device): array => $this->serializeDevice($device, $service),
                $devices,
            ),
        ]);
    }

    public function show(string $publicId): ResponseInterface
    {
        $model = new DeviceModel();
        /** @var Device|null $device */
        $device = $model->where('public_id', $publicId)->first();

        if ($device === null) {
            return $this->response->setStatusCode(404)->setJSON([
                'error' => ['code' => 'device_not_found', 'message' => 'The requested device was not found.'],
            ]);
        }

        return $this->response->setJSON([
            'data' => $this->serializeDevice($device, new DeviceEnrollmentService($model)),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeDevice(Device $device, ?DeviceEnrollmentService $service = null): array
    {
        $service ??= new DeviceEnrollmentService();

        return [
            'id'                 => $device->public_id,
            'name'               => $device->name,
            'status'             => $device->status,
            'connection_status'  => $service->connectionStatus($device),
            'app_version'        => $device->app_version,
            'platform'           => $device->platform,
            'ldg_version'        => $device->ldg_version,
            'timezone'           => $device->timezone,
            'ip_address'         => $device->ip_address,
            'last_seen_at'       => $device->last_seen_at?->toDateTimeString(),
            'registered_at'      => $device->registered_at?->toDateTimeString(),
            'inventory_revision' => $device->inventory_revision,
            'asset_revision'     => $device->asset_revision,
            'schedule_revision'  => $device->schedule_revision,
            'created_at'         => $device->created_at?->toDateTimeString(),
        ];
    }

    /** @param array<string, string> $errors */
    private function validationError(array $errors): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'error' => ['code' => 'validation_failed', 'message' => 'The request data is invalid.', 'fields' => $errors],
        ]);
    }

    private function serverError(): ResponseInterface
    {
        return $this->response->setStatusCode(500)->setJSON([
            'error' => ['code' => 'enrollment_failed', 'message' => 'The device enrollment could not be created.'],
        ]);
    }
}
