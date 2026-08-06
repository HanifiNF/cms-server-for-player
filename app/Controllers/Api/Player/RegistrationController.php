<?php

namespace App\Controllers\Api\Player;

use App\Controllers\BaseController;
use App\Libraries\DeviceEnrollmentService;
use App\Libraries\EnrollmentException;
use App\Libraries\OperatorAuthException;
use App\Libraries\OperatorAuthService;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeZone;
use Config\Player;
use Throwable;

class RegistrationController extends BaseController
{
    public function register(): ResponseInterface
    {
        if (! config(Player::class)->enablePairingCode) {
            return $this->response->setStatusCode(403)->setJSON([
                'error' => ['code' => 'pairing_code_disabled', 'message' => 'Pairing-code registration is disabled. Use operator login.'],
            ]);
        }
        $input = $this->request->getJSON(true) ?? [];
        $code = trim((string) ($input['enrollment_code'] ?? ''));
        $fingerprint = trim((string) ($input['device_fingerprint'] ?? ''));
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Jakarta'));

        $errors = [];
        if ($code === '') {
            $errors['enrollment_code'] = 'Enrollment code is required.';
        }
        if (mb_strlen($fingerprint) < 8 || mb_strlen($fingerprint) > 255) {
            $errors['device_fingerprint'] = 'Device fingerprint must contain between 8 and 255 characters.';
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Timezone must be a valid IANA timezone identifier.';
        }
        foreach (['app_version' => 32, 'platform' => 80] as $field => $limit) {
            if (isset($input[$field]) && mb_strlen((string) $input[$field]) > $limit) {
                $errors[$field] = sprintf('%s must not exceed %d characters.', $field, $limit);
            }
        }
        if ($errors !== []) {
            return $this->validationError($errors);
        }

        try {
            $result = (new DeviceEnrollmentService())->register($code, $fingerprint, [
                'app_version' => isset($input['app_version']) ? trim((string) $input['app_version']) : null,
                'platform'    => isset($input['platform']) ? trim((string) $input['platform']) : null,
                'timezone'    => $timezone,
            ], $this->request->getIPAddress());
        } catch (EnrollmentException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON([
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Player registration failed: {message}', ['message' => $exception->getMessage()]);

            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'registration_failed', 'message' => 'The player registration could not be completed.'],
            ]);
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(201)->setJSON([
            'data' => [
                'device_id'        => $result['device']->public_id,
                'device_name'      => $result['device']->name,
                'device_location'  => $result['device']->location,
                'device_timezone'  => $result['device']->timezone,
                'token'            => $result['token'],
                'token_type'       => 'Bearer',
                'heartbeat_interval_seconds' => 10,
            ],
        ]);
    }

    public function claim(): ResponseInterface
    {
        try {
            $auth = (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'), ['operator']);
        } catch (OperatorAuthException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]]);
        }

        $input = $this->request->getJSON(true) ?? [];
        $deviceId = trim((string) ($input['device_id'] ?? ''));
        $fingerprint = trim((string) ($input['device_fingerprint'] ?? ''));
        if ($deviceId === '' || mb_strlen($fingerprint) < 8 || mb_strlen($fingerprint) > 255) {
            return $this->response->setStatusCode(422)->setJSON(['error' => ['code' => 'validation_failed', 'message' => 'Device ID and a valid fingerprint are required.']]);
        }

        try {
            $result = (new DeviceEnrollmentService())->claim($auth['user'], $deviceId, $fingerprint, [
                'app_version' => isset($input['app_version']) ? mb_substr(trim((string) $input['app_version']), 0, 32) : null,
                'platform' => isset($input['platform']) ? mb_substr(trim((string) $input['platform']), 0, 80) : null,
                'timezone' => isset($input['timezone']) && in_array((string) $input['timezone'], DateTimeZone::listIdentifiers(), true) ? (string) $input['timezone'] : 'Asia/Jakarta',
            ], $this->request->getIPAddress());
        } catch (EnrollmentException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]]);
        } catch (Throwable $exception) {
            log_message('error', 'Device claim failed: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['error' => ['code' => 'claim_failed', 'message' => 'The device could not be claimed.']]);
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setStatusCode(201)->setJSON(['data' => [
            'device_id' => $result['device']->public_id, 'device_name' => $result['device']->name,
            'device_location' => $result['device']->location, 'device_timezone' => $result['device']->timezone,
            'token' => $result['token'], 'token_type' => 'Bearer', 'heartbeat_interval_seconds' => 10,
        ]]);
    }

    /** @param array<string, string> $errors */
    private function validationError(array $errors): ResponseInterface
    {
        return $this->response->setStatusCode(422)->setJSON([
            'error' => ['code' => 'validation_failed', 'message' => 'The request data is invalid.', 'fields' => $errors],
        ]);
    }
}
