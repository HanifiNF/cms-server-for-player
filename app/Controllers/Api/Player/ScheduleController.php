<?php

namespace App\Controllers\Api\Player;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\EnrollmentException;
use App\Libraries\ScheduleService;
use App\Libraries\AssetExpiryService;
use App\Models\DeviceModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class ScheduleController extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $device = (new DeviceEnrollmentService())->authenticate($this->request->getHeaderLine('Authorization'));
            (new AssetExpiryService())->expireDue();
            $device = (new DeviceModel())->find($device->id);
            $payload = (new ScheduleService())->playerPayload($device);
        } catch (EnrollmentException $error) {
            return $this->response->setStatusCode($error->httpStatus)->setJSON([
                'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
            ]);
        } catch (Throwable $error) {
            log_message('error', 'Player schedule sync failed: {message}', ['message' => $error->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'schedule_sync_failed', 'message' => 'Player schedules could not be loaded.'],
            ]);
        }
        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON(['data' => $payload]);
    }
}
