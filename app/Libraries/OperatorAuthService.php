<?php

namespace App\Libraries;

use App\Entities\AuthSession;
use App\Entities\User;
use App\Models\AuthSessionModel;
use App\Models\UserModel;
use Config\Auth;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class OperatorAuthService
{
    public function __construct(
        private readonly ?UserModel $users = null,
        private readonly ?AuthSessionModel $sessions = null,
        private readonly ?Auth $config = null,
    ) {
    }

    /** @return array{user: User, session: AuthSession, token: string} */
    public function login(string $email, string $password, ?string $ipAddress, ?string $userAgent): array
    {
        /** @var User|null $user */
        $user = $this->userModel()->where('email', mb_strtolower(trim($email)))->first();
        if ($user === null || $user->status !== 'active' || ! password_verify($password, (string) $user->password_hash)) {
            throw new OperatorAuthException('invalid_credentials', 'Email or password is incorrect.', 401);
        }

        $now = $this->now();
        $expiresAt = $now->modify(sprintf('+%d minutes', $this->authConfig()->operatorSessionMinutes));
        $token = $this->base64Url(random_bytes(32));
        $sessionId = $this->sessionModel()->insert([
            'public_id'    => $this->uuidV4(),
            'user_id'      => $user->id,
            'token_hash'   => hash('sha256', $token),
            'expires_at'   => $expiresAt->format('Y-m-d H:i:s'),
            'last_used_at' => $now->format('Y-m-d H:i:s'),
            'ip_address'   => $ipAddress,
            'user_agent'   => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ], true);
        if ($sessionId === false) {
            throw new RuntimeException('The operator session could not be created.');
        }

        $this->userModel()->update($user->id, ['last_login_at' => $now->format('Y-m-d H:i:s')]);
        /** @var AuthSession $session */
        $session = $this->sessionModel()->find($sessionId);

        return ['user' => $user, 'session' => $session, 'token' => $token];
    }

    /** @return array{user: User, session: AuthSession} */
    public function authenticate(string $authorizationHeader, array $roles = ['admin', 'operator']): array
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches)) {
            throw new OperatorAuthException('missing_operator_token', 'An operator Bearer token is required.', 401);
        }

        /** @var AuthSession|null $session */
        $session = $this->sessionModel()
            ->where('token_hash', hash('sha256', trim($matches[1])))
            ->where('revoked_at', null)
            ->where('expires_at >=', $this->now()->format('Y-m-d H:i:s'))
            ->first();
        if ($session === null) {
            throw new OperatorAuthException('invalid_operator_token', 'The operator session is invalid or expired.', 401);
        }

        /** @var User|null $user */
        $user = $this->userModel()->find($session->user_id);
        if ($user === null || $user->status !== 'active') {
            throw new OperatorAuthException('invalid_operator_token', 'The operator account is inactive.', 401);
        }
        if (! in_array($user->role, $roles, true)) {
            throw new OperatorAuthException('insufficient_role', 'This operator is not allowed to perform that action.', 403);
        }

        $this->sessionModel()->update($session->id, ['last_used_at' => $this->now()->format('Y-m-d H:i:s')]);
        return ['user' => $user, 'session' => $session];
    }

    public function logout(AuthSession $session): void
    {
        $this->sessionModel()->update($session->id, ['revoked_at' => $this->now()->format('Y-m-d H:i:s')]);
    }

    private function userModel(): UserModel
    {
        return $this->users ?? new UserModel();
    }

    private function sessionModel(): AuthSessionModel
    {
        return $this->sessions ?? new AuthSessionModel();
    }

    private function authConfig(): Auth
    {
        return $this->config ?? config(Auth::class);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
