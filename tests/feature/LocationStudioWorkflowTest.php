<?php

use App\Models\DeviceModel;
use App\Models\LocationModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Security;

/** @internal */
final class LocationStudioWorkflowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';

    public function testAdministratorCreatesLocationAndStudioWithoutBreakingPairingContract(): void
    {
        $adminId = $this->user('location-admin@example.com', 'Location Admin', 'admin');
        $operatorId = $this->user('location-operator@example.com', 'Location Operator', 'operator');

        $created = $this->postForm('/control/locations', [
            'name' => 'Bogor', 'code' => 'BGR', 'address' => 'Bogor City',
            'timezone' => 'Asia/Jakarta',
        ], $adminId);
        $created->assertRedirectTo('/control/locations');
        $location = (new LocationModel())->where('code', 'BGR')->first();
        $this->assertNotNull($location);

        $studioResponse = $this->postForm('/control/locations/' . $location->public_id . '/studios', [
            'name' => 'Studio 1',
            'timezone' => '', 'assigned_user_id' => $operatorId,
        ], $adminId);
        $studioResponse->assertRedirectTo('/control/locations/' . $location->public_id);
        $studio = (new DeviceModel())->where('name', 'Studio 1')->first();
        $this->assertNotNull($studio);
        $this->assertSame((int) $location->id, (int) $studio->location_id);
        $this->assertSame('Bogor', $studio->location);
        $this->assertSame('Asia/Jakarta', $studio->timezone);

        $login = $this->withBodyFormat('json')->post('/api/auth/login', [
            'email' => 'location-operator@example.com', 'password' => 'Password-For-Tests-2026!',
        ]);
        $login->assertOK();
        $token = json_decode($login->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']['token'];
        $available = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/operator/devices/available');
        $available->assertOK();
        $payload = json_decode($available->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $this->assertSame('Studio 1', $payload[0]['name']);
        $this->assertSame('Bogor', $payload[0]['location']);
        $this->assertSame($location->public_id, $payload[0]['location_id']);

        $page = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/locations');
        $page->assertOK();
        $page->assertSee('Locations and Studios');
        $page->assertSee('Studio 1');
        $detail = $this->withSession(['cms_web_user_id' => $adminId])->get('/control/locations/' . $location->public_id);
        $detail->assertOK();
        $detail->assertSee('Studios at this Location');
        $detail->assertSee('Add new operator');
    }

    public function testLocationWorkflowCreatesOperatorResetsPairingRevokesAndDeletesStudio(): void
    {
        $adminId = $this->user('workflow-admin@example.com', 'Workflow Admin', 'admin');
        $locationId = (new LocationModel())->insert([
            'public_id' => '51000000-2222-4333-8444-555555555555', 'name' => 'Bandung',
            'code' => 'BDG', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $studioId = (new DeviceModel())->insert([
            'public_id' => '52000000-2222-4333-8444-555555555555', 'name' => 'Studio Bandung',
            'status' => 'pending', 'timezone' => 'Asia/Jakarta', 'location' => 'Bandung', 'location_id' => $locationId,
        ], true);
        $location = (new LocationModel())->find($locationId);
        $studio = (new DeviceModel())->find($studioId);

        $created = $this->postForm('/control/locations/' . $location->public_id . '/studios/' . $studio->public_id . '/operators', [
            'name' => 'Bandung Operator', 'email' => 'bandung-operator@example.com',
            'password' => 'Bandung-Operator-2026!', 'password_confirmation' => 'Bandung-Operator-2026!',
        ], $adminId);
        $created->assertRedirectTo('/control/locations/' . $location->public_id);
        $operator = (new UserModel())->where('email', 'bandung-operator@example.com')->first();
        $this->assertNotNull($operator);
        $this->assertSame('operator', $operator->role);
        $this->assertSame((int) $operator->id, (int) (new DeviceModel())->find($studioId)->assigned_user_id);

        $token = 'old-studio-token';
        (new DeviceModel())->update($studioId, [
            'status' => 'active', 'device_key_hash' => hash('sha256', $token),
            'fingerprint_hash' => hash('sha256', 'old-installation'), 'claimed_by' => $operator->id,
        ]);
        $reset = $this->postForm('/control/locations/' . $location->public_id . '/studios/' . $studio->public_id . '/reset-pairing', [], $adminId);
        $reset->assertRedirectTo('/control/locations/' . $location->public_id);
        $resetStudio = (new DeviceModel())->find($studioId);
        $this->assertSame('pending', $resetStudio->status);
        $this->assertNull($resetStudio->device_key_hash);
        $this->assertNull($resetStudio->fingerprint_hash);
        $this->assertSame((int) $operator->id, (int) $resetStudio->assigned_user_id);
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->withBodyFormat('json')
            ->post('/api/player/heartbeat', [])->assertStatus(401);

        (new DeviceModel())->update($studioId, ['status' => 'active', 'device_key_hash' => hash('sha256', 'new-token')]);
        $this->postForm('/control/locations/' . $location->public_id . '/studios/' . $studio->public_id . '/revoke', [], $adminId)
            ->assertRedirectTo('/control/locations/' . $location->public_id);
        $this->assertSame('revoked', (new DeviceModel())->find($studioId)->status);
        $this->postForm('/control/locations/' . $location->public_id . '/studios/' . $studio->public_id . '/delete', [], $adminId)
            ->assertRedirectTo('/control/locations/' . $location->public_id);
        $this->assertNull((new DeviceModel())->find($studioId));

        $this->withSession(['cms_web_user_id' => $adminId])->get('/control/devices')->assertRedirectTo('/control/locations');
    }

    public function testInactiveLocationBlocksNewPairingAndCannotBeDeletedWhileItContainsStudio(): void
    {
        $adminId = $this->user('inactive-admin@example.com', 'Inactive Admin', 'admin');
        $operatorId = $this->user('inactive-operator@example.com', 'Inactive Operator', 'operator');
        $locationId = (new LocationModel())->insert([
            'public_id' => '10000000-2222-4333-8444-555555555555', 'name' => 'Jakarta',
            'code' => 'JKT', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $studioId = (new DeviceModel())->insert([
            'public_id' => '20000000-2222-4333-8444-555555555555', 'name' => 'Studio Jakarta',
            'status' => 'pending', 'timezone' => 'Asia/Jakarta', 'location' => 'Jakarta',
            'location_id' => $locationId, 'assigned_user_id' => $operatorId,
        ], true);
        $location = (new LocationModel())->find($locationId);

        $this->postForm('/control/locations/' . $location->public_id . '/status', ['status' => 'inactive'], $adminId)
            ->assertRedirectTo('/control/locations');
        $this->postForm('/control/locations/' . $location->public_id . '/delete', [], $adminId)
            ->assertRedirectTo('/control/locations');
        $this->assertNotNull((new LocationModel())->find($locationId));
        $this->assertNotNull((new DeviceModel())->find($studioId));

        $login = $this->withBodyFormat('json')->post('/api/auth/login', [
            'email' => 'inactive-operator@example.com', 'password' => 'Password-For-Tests-2026!',
        ]);
        $token = json_decode($login->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']['token'];
        $available = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('/api/operator/devices/available');
        $available->assertOK();
        $this->assertSame([], json_decode($available->response()->getJSON(), true, 512, JSON_THROW_ON_ERROR)['data']);
    }

    public function testLocationRenamePreservesLegacyPlayerPayloadAndHeartbeatTelemetryIsOptional(): void
    {
        $adminId = $this->user('rename-admin@example.com', 'Rename Admin', 'admin');
        $locationId = (new LocationModel())->insert([
            'public_id' => '30000000-2222-4333-8444-555555555555', 'name' => 'Old Bogor',
            'code' => 'BGR', 'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], true);
        $token = 'studio-heartbeat-token';
        $studioId = (new DeviceModel())->insert([
            'public_id' => '40000000-2222-4333-8444-555555555555', 'name' => 'Studio Telemetry',
            'device_key_hash' => hash('sha256', $token), 'status' => 'active',
            'timezone' => 'Asia/Jakarta', 'location' => 'Old Bogor', 'location_id' => $locationId,
        ], true);
        $location = (new LocationModel())->find($locationId);
        $this->postForm('/control/locations/' . $location->public_id . '/update', [
            'name' => 'Bogor', 'code' => 'BGR', 'address' => '',
            'timezone' => 'Asia/Jakarta', 'status' => 'active',
        ], $adminId)->assertRedirectTo('/control/locations');
        $this->assertSame('Bogor', (new DeviceModel())->find($studioId)->location);

        $heartbeat = $this->withHeaders(['Authorization' => 'Bearer ' . $token])->withBodyFormat('json')
            ->post('/api/player/heartbeat', [
                'playback_state' => 'playing',
                'playback_schedule_id' => '50000000-2222-4333-8444-555555555555',
            ]);
        $heartbeat->assertOK();
        $heartbeat->assertJSONFragment(['data' => ['device_location' => 'Bogor', 'playback_state' => 'playing']]);
        $studio = (new DeviceModel())->find($studioId);
        $this->assertSame('playing', $studio->playback_state);
        $this->assertNotNull($studio->playback_updated_at);

        // An older Player that omits telemetry remains accepted and does not erase the last report.
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->withBodyFormat('json')
            ->post('/api/player/heartbeat', ['app_version' => '1.1.0'])->assertOK();
        $this->assertSame('playing', (new DeviceModel())->find($studioId)->playback_state);
    }

    private function user(string $email, string $name, string $role): int
    {
        return (new UserModel())->insert([
            'email' => $email, 'name' => $name,
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => $role, 'status' => 'active',
        ], true);
    }

    /** @param array<string, mixed> $data */
    private function postForm(string $uri, array $data, int $adminId)
    {
        $security = config(Security::class);
        $token = csrf_hash();
        return $this->withSession(['cms_web_user_id' => $adminId])
            ->withHeaders(['Cookie' => $security->cookieName . '=' . $token])
            ->post($uri, [...$data, $security->tokenName => $token]);
    }
}
