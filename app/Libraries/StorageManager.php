<?php

namespace App\Libraries;

use App\Libraries\Storage\LocalStorageDriver;
use App\Libraries\Storage\FtpsStorageDriver;
use App\Libraries\Storage\SftpStorageDriver;
use App\Libraries\Storage\StorageDriverInterface;
use App\Models\StorageProfileModel;
use RuntimeException;

final class StorageManager
{
    private StorageProfileModel $profiles;

    public function __construct(?StorageProfileModel $profiles = null)
    {
        $this->profiles = $profiles ?? new StorageProfileModel();
    }

    public function defaultProfile(): object
    {
        $profile = $this->profiles->where('is_default', true)->where('status', 'active')->first();
        if ($profile === null) throw new RuntimeException('No active default storage profile is configured.');
        return $profile;
    }

    public function profile(?int $id): object
    {
        $profile = $id !== null ? $this->profiles->find($id) : null;
        return $profile ?? $this->defaultProfile();
    }

    public function putFile(object $profile, string $sourcePath, string $key): void
    {
        $this->driver($profile)->putFile($sourcePath, $key);
    }

    public function materialize(object $profile, string $key): ?string
    {
        return $this->driver($profile)->materialize($key);
    }

    public function delete(object $profile, string $key): void
    {
        if ($key !== '') $this->driver($profile)->delete($key);
    }

    /** @return array{ok:bool,message:string} */
    public function test(object $profile): array
    {
        return $this->driver($profile)->testConnection();
    }

    public function displayLocation(object $profile): string
    {
        return $this->driver($profile)->displayLocation();
    }

    public function temporaryPath(string $suffix = ''): string
    {
        $directory = WRITEPATH . 'storage-staging';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The storage staging directory could not be created.');
        }
        $path = tempnam($directory, 'cms-');
        if ($path === false) throw new RuntimeException('A storage staging file could not be created.');
        if ($suffix !== '') {
            $target = $path . $suffix;
            if (! rename($path, $target)) throw new RuntimeException('The storage staging file could not be prepared.');
            return $target;
        }
        return $path;
    }

    private function driver(object $profile): StorageDriverInterface
    {
        $config = json_decode((string) ($profile->config ?? '{}'), true);
        if (! is_array($config)) throw new RuntimeException('The storage profile configuration is invalid.');
        $config['_profile_id'] = (string) ($profile->public_id ?? $profile->id ?? 'unbound');
        return match ((string) $profile->driver) {
            'local' => new LocalStorageDriver($config),
            'ftps' => new FtpsStorageDriver($config, (new StorageCredentialService())->decrypt($profile->credentials_encrypted ?? null)),
            'sftp' => new SftpStorageDriver($config, (new StorageCredentialService())->decrypt($profile->credentials_encrypted ?? null)),
            default => throw new RuntimeException('Storage driver "' . (string) $profile->driver . '" is not installed.'),
        };
    }
}
