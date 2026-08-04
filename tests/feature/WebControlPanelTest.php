<?php

use App\Models\DeviceModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class WebControlPanelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testControlPanelRequiresAnAdministratorSession(): void
    {
        $result = $this->get('/control');
        $result->assertRedirectTo('/login');
    }

    public function testFirstRunSetupCreatesTheOnlyInitialAdministrator(): void
    {
        $result = $this->postForm('/setup', [
            'name' => 'First Administrator', 'email' => 'first-admin@example.com',
            'password' => 'First-Admin-Password-2026!', 'password_confirmation' => 'First-Admin-Password-2026!',
        ]);
        $result->assertRedirectTo('/control');

        $admin = (new UserModel())->where('email', 'first-admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(password_verify('First-Admin-Password-2026!', $admin->password_hash));

        $closedSetup = $this->get('/setup');
        $closedSetup->assertRedirectTo('/login');
    }

    public function testAdministratorCanCreateOperatorAndAssignedPlayerFromGui(): void
    {
        $adminId = (new UserModel())->insert([
            'email' => 'web-admin@example.com', 'name' => 'Web Admin',
            'password_hash' => password_hash('Web-Admin-Password-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
        $this->assertIsInt($adminId);

        $operatorResponse = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/operators', [
            'name' => 'Lobby Operator', 'email' => 'lobby-operator@example.com',
            'role' => 'operator', 'password' => 'Lobby-Operator-Password-2026!',
        ]);
        $operatorResponse->assertRedirectTo('/control/operators');
        $operator = (new UserModel())->where('email', 'lobby-operator@example.com')->first();
        $this->assertNotNull($operator);

        $deviceResponse = $this->withSession(['cms_web_user_id' => $adminId])->postForm('/control/devices', [
            'name' => 'Player Lobby', 'location' => 'Lobby lantai 1',
            'timezone' => 'Asia/Jakarta', 'assigned_user_id' => $operator->id,
        ]);
        $deviceResponse->assertRedirectTo('/control/devices');

        $device = (new DeviceModel())->where('name', 'Player Lobby')->first();
        $this->assertNotNull($device);
        $this->assertSame('pending', $device->status);
        $this->assertSame((int) $operator->id, (int) $device->assigned_user_id);

        $page = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/devices');
        $page->assertOK();
        $page->assertSee('Player Lobby');
        $page->assertSee('Lobby Operator');

        (new DeviceModel())->update($device->id, [
            'status' => 'active', 'device_key_hash' => hash('sha256', 'device-token'),
            'fingerprint_hash' => hash('sha256', 'installation-id'),
        ]);
        $revoked = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/devices/' . $device->public_id . '/revoke', []);
        $revoked->assertRedirectTo('/control/devices');
        $revokedDevice = (new DeviceModel())->find($device->id);
        $this->assertSame('revoked', $revokedDevice->status);
        $this->assertNull($revokedDevice->device_key_hash);
        $this->assertNull($revokedDevice->fingerprint_hash);

        $revokedPage = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/devices');
        $revokedPage->assertOK();
        $revokedPage->assertSee('Revoked Players');
        $revokedPage->assertSee('Delete Permanently');

        $deleted = $this->withSession(['cms_web_user_id' => $adminId])
            ->postForm('/control/devices/' . $device->public_id . '/delete', []);
        $deleted->assertRedirectTo('/control/devices');
        $this->assertNull((new DeviceModel())->find($device->id));
    }

    /** @param array<string, mixed> $data */
    private function postForm(string $uri, array $data)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withHeaders(['Cookie' => $security->cookieName . '=' . $token])
            ->post($uri, [...$data, $security->tokenName => $token]);
    }
}
