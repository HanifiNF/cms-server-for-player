<?php

namespace App\Controllers\Api\Player;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\EnrollmentException;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
use Throwable;

class HeartbeatController extends BaseController
{
    public function create(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? [];
        $errors = [];

        foreach (['app_version' => 32, 'platform' => 80] as $field => $limit) {
            if (isset($input[$field]) && mb_strlen((string) $input[$field]) > $limit) {
                $errors[$field] = sprintf('%s must not exceed %d characters.', $field, $limit);
            }
        }
        if (isset($input['timezone']) && ! in_array((string) $input['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Timezone must be a valid IANA timezone identifier.';
        }
        if ($errors !== []) {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => ['code' => 'validation_failed', 'message' => 'The request data is invalid.', 'fields' => $errors],
            ]);
        }

        $service = new DeviceEnrollmentService();

        try {
            $device = $service->authenticate($this->request->getHeaderLine('Authorization'));
            $device = $service->heartbeat($device, $input, $this->request->getIPAddress());
        } catch (EnrollmentException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON([
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Player heartbeat failed: {message}', ['message' => $exception->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'heartbeat_failed', 'message' => 'The player heartbeat could not be stored.'],
            ]);
        }

        return $this->response->setJSON([
            'data' => [
                'device_id'         => $device->public_id,
                'connection_status' => 'online',
                'server_time'       => gmdate(DATE_ATOM),
                'inventory_revision'=> $device->inventory_revision,
                'schedule_revision' => $device->schedule_revision,
            ],
        ]);
    }
}
