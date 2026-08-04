<?php

namespace App\Libraries;

use App\Entities\Device;
use App\Models\DeviceModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Player;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class DeviceEnrollmentService
{
    private DeviceModel $devices;

    private BaseConnection $db;

    private Player $config;

    public function __construct(?DeviceModel $devices = null, ?BaseConnection $db = null, ?Player $config = null)
    {
        $this->devices = $devices ?? new DeviceModel();
        $this->db = $db ?? Database::connect();
        $this->config = $config ?? config(Player::class);
    }

    /** @return array{device: Device, enrollment_code: string, expires_at: string} */
    public function createEnrollment(string $name, string $timezone): array
    {
        $this->assertPepperConfigured();

        $code = $this->generateEnrollmentCode();
        $now = $this->now();
        $expiresAt = $now->modify(sprintf('+%d minutes', $this->config->enrollmentTtlMinutes));

        $id = $this->devices->insert([
            'public_id'              => $this->uuidV4(),
            'name'                   => $name,
            'activation_code_hash'   => $this->enrollmentDigest($code),
            'activation_expires_at'  => $this->databaseTime($expiresAt),
            'status'                 => 'pending',
            'timezone'               => $timezone,
        ], true);

        if ($id === false) {
            throw new RuntimeException('The device enrollment could not be created.');
        }

        /** @var Device $device */
        $device = $this->devices->find($id);

        return [
            'device'          => $device,
            'enrollment_code' => $code,
            'expires_at'      => $expiresAt->format(DATE_ATOM),
        ];
    }

    /** @return array{device: Device, token: string} */
    public function register(string $code, string $fingerprint, array $metadata, ?string $ipAddress): array
    {
        $this->assertPepperConfigured();

        $normalizedCode = $this->normalizeEnrollmentCode($code);
        if ($normalizedCode === '') {
            throw new EnrollmentException('invalid_enrollment_code', 'The enrollment code is invalid.', 422);
        }

        $now = $this->now();
        $fingerprintHash = hash('sha256', $fingerprint);

        $existing = $this->devices
            ->where('fingerprint_hash', $fingerprintHash)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            throw new EnrollmentException('device_already_registered', 'This player is already registered.', 409);
        }

        /** @var Device|null $device */
        $device = $this->devices
            ->where('activation_code_hash', $this->enrollmentDigest($normalizedCode))
            ->where('status', 'pending')
            ->where('activation_expires_at >=', $this->databaseTime($now))
            ->first();

        if ($device === null) {
            throw new EnrollmentException('invalid_or_expired_enrollment', 'The enrollment code is invalid, expired, or has already been used.', 401);
        }

        $token = $this->base64Url(random_bytes(32));
        $updates = [
            'device_key_hash'       => hash('sha256', $token),
            'activation_code_hash'  => null,
            'activation_expires_at' => null,
            'fingerprint_hash'      => $fingerprintHash,
            'status'                => 'active',
            'app_version'           => $metadata['app_version'] ?? null,
            'platform'              => $metadata['platform'] ?? null,
            'timezone'              => $metadata['timezone'] ?? $device->timezone,
            'last_seen_at'          => $this->databaseTime($now),
            'registered_at'         => $this->databaseTime($now),
            'token_last_used_at'    => $this->databaseTime($now),
            'ip_address'            => $ipAddress,
        ];

        $this->db->transStart();
        $updated = $this->devices
            ->where('id', $device->id)
            ->where('status', 'pending')
            ->set($updates)
            ->update();
        $affectedRows = $this->db->affectedRows();
        $this->db->transComplete();

        if (! $updated || $affectedRows !== 1 || ! $this->db->transStatus()) {
            throw new RuntimeException('The player registration could not be completed.');
        }

        /** @var Device $registeredDevice */
        $registeredDevice = $this->devices->find($device->id);

        return ['device' => $registeredDevice, 'token' => $token];
    }

    public function authenticate(string $authorizationHeader): Device
    {
        if (! preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches)) {
            throw new EnrollmentException('missing_player_token', 'A player Bearer token is required.', 401);
        }

        /** @var Device|null $device */
        $device = $this->devices
            ->where('device_key_hash', hash('sha256', trim($matches[1])))
            ->where('status', 'active')
            ->first();

        if ($device === null) {
            throw new EnrollmentException('invalid_player_token', 'The player token is invalid or inactive.', 401);
        }

        return $device;
    }

    public function heartbeat(Device $device, array $metadata, ?string $ipAddress): Device
    {
        $now = $this->now();
        $updates = [
            'last_seen_at'       => $this->databaseTime($now),
            'token_last_used_at' => $this->databaseTime($now),
            'ip_address'         => $ipAddress,
        ];

        foreach (['app_version', 'platform', 'timezone'] as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $updates[$field] = $metadata[$field];
            }
        }

        if (! $this->devices->update($device->id, $updates)) {
            throw new RuntimeException('The player heartbeat could not be stored.');
        }

        /** @var Device $updated */
        $updated = $this->devices->find($device->id);

        return $updated;
    }

    public function connectionStatus(Device $device): string
    {
        if ($device->status !== 'active' || $device->last_seen_at === null) {
            return 'offline';
        }

        $lastSeen = new DateTimeImmutable((string) $device->last_seen_at, new DateTimeZone('UTC'));
        $threshold = $this->now()->modify(sprintf('-%d seconds', $this->config->offlineAfterSeconds));

        return $lastSeen >= $threshold ? 'online' : 'offline';
    }

    private function assertPepperConfigured(): void
    {
        if (strlen($this->config->enrollmentPepper) < 32) {
            throw new RuntimeException('cms.enrollmentPepper must contain at least 32 characters.');
        }
    }

    private function generateEnrollmentCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $characters = '';
        $max = strlen($alphabet) - 1;

        for ($index = 0; $index < 8; $index++) {
            $characters .= $alphabet[random_int(0, $max)];
        }

        return substr($characters, 0, 4) . '-' . substr($characters, 4);
    }

    private function enrollmentDigest(string $code): string
    {
        return hash_hmac('sha256', $this->normalizeEnrollmentCode($code), $this->config->enrollmentPepper);
    }

    private function normalizeEnrollmentCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($code)));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function databaseTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
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

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
