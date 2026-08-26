<?php

namespace App\Libraries\Storage;

use Config\Storage;
use RuntimeException;

final class SftpStorageDriver implements StorageDriverInterface
{
    /** @var array<string, mixed> */
    private array $config;
    private FtpsStorageDriver $delegate;

    /** @param array<string, mixed> $config @param array<string, string> $credentials */
    public function __construct(array $config, array $credentials, ?FtpsTransportInterface $transport = null)
    {
        $this->config = self::normalizeConfig($config);
        if (trim((string) ($credentials['username'] ?? '')) === '' || (string) ($credentials['password'] ?? '') === '') {
            throw new RuntimeException('SFTP username and password are required.');
        }
        $transport ??= new PhpseclibSftpTransport($this->config, $credentials);
        $this->delegate = new FtpsStorageDriver([
            '_profile_id' => $this->config['_profile_id'],
            'host' => $this->config['host'], 'mode' => 'explicit', 'port' => $this->config['port'],
            'remote_root' => $this->config['remote_root'], 'passive' => true,
            'connect_timeout' => $this->config['connect_timeout'], 'transfer_timeout' => $this->config['transfer_timeout'],
            'cache_ttl_seconds' => $this->config['cache_ttl_seconds'], 'cache_max_bytes' => $this->config['cache_max_bytes'],
        ], $credentials, $transport);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public static function normalizeConfig(array $config): array
    {
        $host = mb_strtolower(trim((string) ($config['host'] ?? '')));
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw new RuntimeException('Enter a valid SFTP hostname or IP address without a URL scheme or path.');
        }
        $port = filter_var($config['port'] ?? 22, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new RuntimeException('SFTP port must be between 1 and 65535.');
        $root = '/' . trim(str_replace('\\', '/', (string) ($config['remote_root'] ?? '')), '/');
        self::assertRemotePath($root, 'SFTP remote root');
        $fingerprint = trim((string) ($config['host_key_fingerprint'] ?? ''));
        if (! preg_match('#^SHA256:[A-Za-z0-9+/]{43}=?$#', $fingerprint)) {
            throw new RuntimeException('SFTP host key fingerprint must use the SHA256:Base64 format shown by FileZilla.');
        }
        $fingerprint = 'SHA256:' . rtrim(substr($fingerprint, 7), '=');
        $storage = config(Storage::class);
        $profileId = trim((string) ($config['_profile_id'] ?? 'unbound'));
        if (! preg_match('/^[A-Za-z0-9-]{1,80}$/', $profileId)) throw new RuntimeException('SFTP cache namespace is invalid.');
        return [
            'host' => $host, 'port' => (int) $port, 'remote_root' => $root,
            'host_key_fingerprint' => $fingerprint,
            'connect_timeout' => self::boundedInteger($config['connect_timeout'] ?? 15, 5, 120, 'SFTP connection timeout'),
            'transfer_timeout' => self::boundedInteger($config['transfer_timeout'] ?? 3600, 30, 86400, 'SFTP transfer timeout'),
            'cache_ttl_seconds' => self::boundedInteger($config['cache_ttl_seconds'] ?? $storage->defaultCacheTtlSeconds, 60, 604800, 'SFTP cache lifetime'),
            'cache_max_bytes' => self::boundedInteger($config['cache_max_bytes'] ?? $storage->defaultCacheMaxBytes, 1073741824, 1099511627776, 'SFTP cache capacity'),
            '_profile_id' => $profileId,
        ];
    }

    public static function fingerprintHostKey(string $hostKey): string
    {
        $parts = preg_split('/\s+/', trim($hostKey));
        $encoded = $parts[1] ?? '';
        $blob = base64_decode($encoded, true);
        if ($blob === false || $blob === '') throw new RuntimeException('SFTP server returned an unsupported SSH host key.');
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public function putFile(string $sourcePath, string $key): void { $this->delegate->putFile($sourcePath, $key); }
    public function materialize(string $key): ?string { return $this->delegate->materialize($key); }
    public function exists(string $key): bool { return $this->delegate->exists($key); }
    public function delete(string $key): void { $this->delegate->delete($key); }

    public function testConnection(): array
    {
        $result = $this->delegate->testConnection();
        $result['message'] = str_replace(
            ['FTPS TLS, login', 'FTPS'],
            ['SFTP host key, login', 'SFTP'],
            $result['message']
        );
        return $result;
    }

    public function displayLocation(): string
    {
        return 'sftp://' . $this->config['host'] . ':' . $this->config['port'] . $this->config['remote_root'];
    }

    private static function assertRemotePath(string $path, string $label): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/[\r\n]/', $path)) throw new RuntimeException("{$label} is invalid.");
        foreach (explode('/', trim($path, '/')) as $part) if ($part === '..' || $part === '.') throw new RuntimeException("{$label} cannot contain traversal segments.");
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($validated === false) throw new RuntimeException("{$label} must be between {$minimum} and {$maximum}.");
        return (int) $validated;
    }
}
