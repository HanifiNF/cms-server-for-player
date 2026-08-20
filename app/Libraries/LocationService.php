<?php

namespace App\Libraries;

use App\Entities\Location;
use App\Models\DeviceModel;
use App\Models\LocationModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

class LocationService
{
    public function __construct(
        private ?LocationModel $locations = null,
        private ?BaseConnection $db = null,
    ) {
        $this->locations ??= new LocationModel();
        $this->db ??= Database::connect();
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): Location
    {
        $values = $this->normalize($input);
        $values['public_id'] = $this->uuidV4();
        $id = $this->locations->insert($values, true);
        if (! is_int($id)) throw new RuntimeException('Location could not be created.');
        return $this->locations->find($id);
    }

    /** @param array<string, mixed> $input */
    public function update(Location $location, array $input): Location
    {
        $values = $this->normalize($input, (int) $location->id);
        $this->db->transBegin();
        try {
            if (! $this->locations->update($location->id, $values)) throw new RuntimeException('Location could not be updated.');
            // Keep the legacy display field synchronized for already-deployed Players.
            $this->db->table('devices')->where('location_id', $location->id)->update(['location' => $values['name']]);
            if ($this->db->transStatus() === false) throw new RuntimeException('Location update transaction failed.');
            $this->db->transCommit();
        } catch (\Throwable $error) {
            $this->db->transRollback();
            throw $error;
        }
        return $this->locations->find($location->id);
    }

    public function setStatus(Location $location, string $status): Location
    {
        if (! in_array($status, ['active', 'inactive'], true)) throw new InvalidArgumentException('Location status is invalid.');
        if (! $this->locations->update($location->id, ['status' => $status])) throw new RuntimeException('Location status could not be updated.');
        return $this->locations->find($location->id);
    }

    public function delete(Location $location): void
    {
        if ($location->status !== 'inactive') throw new InvalidArgumentException('Deactivate the Location before deleting it.');
        if ((new DeviceModel())->where('location_id', $location->id)->countAllResults() > 0) {
            throw new InvalidArgumentException('Move or delete every Studio in this Location first.');
        }
        if (! $this->locations->delete($location->id)) throw new RuntimeException('Location could not be deleted.');
    }

    public function findSelection(?string $publicId, ?string $legacyName = null, string $timezone = 'Asia/Jakarta'): ?Location
    {
        $publicId = trim((string) $publicId);
        if ($publicId !== '') return $this->locations->where('public_id', $publicId)->first();

        // Compatibility for older CMS clients and test fixtures that still send a text location.
        $legacyName = trim((string) $legacyName);
        if ($legacyName === '') return null;
        $existing = $this->locations->where('LOWER(name)', mb_strtolower($legacyName))->first();
        if ($existing !== null) return $existing;
        return $this->create([
            'name' => $legacyName,
            'code' => $this->availableCode($legacyName),
            'timezone' => $timezone,
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, string|null> */
    private function normalize(array $input, ?int $excludeId = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $address = trim((string) ($input['address'] ?? '')) ?: null;
        $timezone = trim((string) ($input['timezone'] ?? 'Asia/Jakarta'));
        $status = trim((string) ($input['status'] ?? 'active'));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 160) $errors[] = 'Name is required and must not exceed 160 characters.';
        if (! preg_match('/^[A-Z0-9][A-Z0-9-]{0,23}$/', $code)) $errors[] = 'Code must contain 1–24 uppercase letters, numbers, or hyphens.';
        if ($address !== null && mb_strlen($address) > 1000) $errors[] = 'Address must not exceed 1000 characters.';
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) $errors[] = 'Timezone is invalid.';
        if (! in_array($status, ['active', 'inactive'], true)) $errors[] = 'Status is invalid.';
        $duplicate = $this->locations->where('code', $code);
        if ($excludeId !== null) $duplicate->where('id !=', $excludeId);
        if ($code !== '' && $duplicate->first() !== null) $errors[] = 'Location code is already in use.';
        if ($errors !== []) throw new InvalidArgumentException(implode(' ', $errors));
        return compact('name', 'code', 'address', 'timezone', 'status');
    }

    private function availableCode(string $name): string
    {
        $base = strtoupper(trim((string) preg_replace('/[^A-Z0-9]+/i', '-', $name), '-')) ?: 'LOCATION';
        $base = mb_substr($base, 0, 24);
        $candidate = $base;
        $suffix = 2;
        while ($this->locations->where('code', $candidate)->first() !== null) {
            $candidate = mb_substr($base, 0, 20) . '-' . $suffix++;
        }
        return $candidate;
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
