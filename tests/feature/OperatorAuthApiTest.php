<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/** @internal */
final class OperatorAuthApiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testOperatorLoginAssignmentClaimAndDashboardSessionBoundaries(): void
    {
        $users = new UserModel();
        $adminId = $users->insert([
            'email' => 'admin-test@example.com', 'name' => 'Admin Test',
            'password_hash' => password_hash('Admin-Test-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $operatorId = $users->insert([
            'email' => 'operator-test@example.com', 'name' => 'Operator Test',
            'password_hash' => password_hash('Operator-Test-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'operator', 'status' => 'active',
        ], true);
        $this->assertIsInt($adminId);
        $this->assertIsInt($operatorId);

        $invalidLogin = $this->withBodyFormat('json')->post('/api/auth/login', [
            'email' => 'operator-test@example.com', 'password' => 'wrong-password',
        ]);
        $invalidLogin->assertStatus(401);
        $invalidLogin->assertJSONFragment(['error' => ['code' => 'invalid_credentials']]);

        $operatorLogin = $this->login('operator-test@example.com', 'Operator-Test-Password-2026!');
        $operatorToken = $operatorLogin['token'];
        $this->assertSame('operator', $operatorLogin['user']['role']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/',
            $operatorLogin['expires_at'],
        );
        $this->assertSame('+00:00', (new DateTimeImmutable($operatorLogin['expires_at']))->format('P'));

        $operatorCreate = $this->withHeaders(['Authorization' => 'Bearer ' . $operatorToken])
            ->withBodyFormat('json')->post('/api/operator/devices', ['name' => 'Forbidden Device']);
        $operatorCreate->assertStatus(403);
        $operatorCreate->assertJSONFragment(['error' => ['code' => 'insufficient_role']]);

        $adminLogin = $this->login('admin-test@example.com', 'Admin-Test-Password-2026!');
        $created = $this->withHeaders(['Authorization' => 'Bearer ' . $adminLogin['token']])
            ->withBodyFormat('json')->post('/api/operator/devices', [
                'name' => 'Assigned Lobby Player', 'location' => 'Lobby',
                'timezone' => 'Asia/Jakarta', 'assigned_user_id' => $operatorId,
            ]);
        $created->assertStatus(201);
        $device = json_decode($created->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];

        $available = $this->withHeaders(['Authorization' => 'Bearer ' . $operatorToken])
            ->get('/api/operator/devices/available');
        $available->assertStatus(200);
        $availableData = json_decode($available->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertCount(1, $availableData);
        $this->assertSame($device['id'], $availableData[0]['id']);

        $claimed = $this->withHeaders(['Authorization' => 'Bearer ' . $operatorToken])
            ->withBodyFormat('json')->post('/api/player/claim', [
                'device_id' => $device['id'], 'device_fingerprint' => 'operator-auth-test-installation',
                'app_version' => '1.1.0', 'platform' => 'win32-x64', 'timezone' => 'Asia/Jakarta',
            ]);
        $claimed->assertStatus(201);
        $claimData = json_decode($claimed->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertNotSame('', $claimData['token']);
        $this->assertSame('Assigned Lobby Player', $claimData['device_name']);
        $this->assertSame('Lobby', $claimData['device_location']);
        $this->assertSame('Asia/Jakarta', $claimData['device_timezone']);

        $heartbeat = $this->withHeaders(['Authorization' => 'Bearer ' . $claimData['token']])
            ->withBodyFormat('json')->post('/api/player/heartbeat', []);
        $heartbeat->assertStatus(200);

        $deviceTokenIsNotOperator = $this->withHeaders(['Authorization' => 'Bearer ' . $claimData['token']])
            ->get('/api/auth/me');
        $deviceTokenIsNotOperator->assertStatus(401);

        $logout = $this->withHeaders(['Authorization' => 'Bearer ' . $operatorToken])
            ->withBodyFormat('json')->post('/api/auth/logout', []);
        $logout->assertStatus(200);
        $expiredSession = $this->withHeaders(['Authorization' => 'Bearer ' . $operatorToken])->get('/api/auth/me');
        $expiredSession->assertStatus(401);
    }

    /** @return array<string, mixed> */
    private function login(string $email, string $password): array
    {
        $response = $this->withBodyFormat('json')->post('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertStatus(200);
        return json_decode($response->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }
}
