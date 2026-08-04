<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Entities\User;
use App\Libraries\OperatorAuthException;
use App\Libraries\OperatorAuthService;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Auth;
use Throwable;

class AuthController extends BaseController
{
    public function login(): ResponseInterface
    {
        $input = $this->request->getJSON(true) ?? [];
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            return $this->response->setStatusCode(422)->setJSON([
                'error' => ['code' => 'validation_failed', 'message' => 'A valid email and password are required.'],
            ]);
        }

        $config = config(Auth::class);
        $rateKey = 'operator-login-' . hash('sha256', $this->request->getIPAddress() . '|' . $email);
        if (! service('throttler')->check($rateKey, $config->loginAttemptsPerMinute, 60)) {
            return $this->response->setStatusCode(429)->setJSON([
                'error' => ['code' => 'too_many_login_attempts', 'message' => 'Too many login attempts. Try again later.'],
            ]);
        }

        try {
            $result = (new OperatorAuthService())->login(
                $email,
                $password,
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString(),
            );
        } catch (OperatorAuthException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON([
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Operator login failed: {message}', ['message' => $exception->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'error' => ['code' => 'login_failed', 'message' => 'The operator could not be signed in.'],
            ]);
        }

        return $this->response->setHeader('Cache-Control', 'no-store')->setJSON([
            'data' => [
                'token'      => $result['token'],
                'token_type' => 'Bearer',
                // Always include the timezone offset. A timestamp without one is
                // interpreted as local time by JavaScript clients.
                'expires_at' => $result['session']->expires_at->format(DATE_ATOM),
                'user'       => $this->serializeUser($result['user']),
            ],
        ]);
    }

    public function me(): ResponseInterface
    {
        try {
            $auth = (new OperatorAuthService())->authenticate($this->request->getHeaderLine('Authorization'));
            return $this->response->setJSON(['data' => ['user' => $this->serializeUser($auth['user'])]]);
        } catch (OperatorAuthException $exception) {
            return $this->authError($exception);
        }
    }

    public function logout(): ResponseInterface
    {
        $service = new OperatorAuthService();
        try {
            $auth = $service->authenticate($this->request->getHeaderLine('Authorization'));
            $service->logout($auth['session']);
            return $this->response->setJSON(['data' => ['status' => 'logged_out']]);
        } catch (OperatorAuthException $exception) {
            return $this->authError($exception);
        }
    }

    /** @return array<string, mixed> */
    private function serializeUser(User $user): array
    {
        return ['id' => (int) $user->id, 'email' => $user->email, 'name' => $user->name, 'role' => $user->role];
    }

    private function authError(OperatorAuthException $exception): ResponseInterface
    {
        return $this->response->setStatusCode($exception->httpStatus)->setJSON([
            'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
        ]);
    }
}
