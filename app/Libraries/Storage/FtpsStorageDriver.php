<?php

namespace App\Libraries\Storage;

use Config\Storage;
use RuntimeException;
use Throwable;

final class FtpsStorageDriver implements StorageDriverInterface
{
    /** @var array<string, mixed> */
    private array $config;
    private FtpsTransportInterface $transport;

    /** @param array<string, mixed> $config @param array<string, string> $credentials */
    public function __construct(array $config, array $credentials, ?FtpsTransportInterface $transport = null)
    {
        $this->config = self::normalizeConfig($config);
        if (trim((string) ($credentials['username'] ?? '')) === '' || (string) ($credentials['password'] ?? '') === '') {
            throw new RuntimeException('FTPS username and password are required.');
        }
        $this->transport = $transport ?? new CurlFtpsTransport($this->config, $credentials);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public static function normalizeConfig(array $config): array
    {
        $host = mb_strtolower(trim((string) ($config['host'] ?? '')));
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw new RuntimeException('Enter a valid FTPS hostname without a URL scheme or path.');
        }
        $mode = trim((string) ($config['mode'] ?? 'explicit'));
        if (! in_array($mode, ['explicit', 'implicit'], true)) throw new RuntimeException('FTPS mode must be explicit or implicit.');
        $port = filter_var($config['port'] ?? ($mode === 'implicit' ? 990 : 21), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new RuntimeException('FTPS port must be between 1 and 65535.');
        $root = '/' . trim(str_replace('\\', '/', (string) ($config['remote_root'] ?? '')), '/');
        self::assertRemotePath($root, 'FTPS remote root');

        $storageConfig = config(Storage::class);
        $connectTimeout = self::boundedInteger($config['connect_timeout'] ?? 15, 5, 120, 'FTPS connection timeout');
        $transferTimeout = self::boundedInteger($config['transfer_timeout'] ?? 3600, 30, 86400, 'FTPS transfer timeout');
        $cacheTtl = self::boundedInteger($config['cache_ttl_seconds'] ?? $storageConfig->defaultCacheTtlSeconds, 60, 604800, 'FTPS cache lifetime');
        $cacheMax = self::boundedInteger($config['cache_max_bytes'] ?? $storageConfig->defaultCacheMaxBytes, 1073741824, 1099511627776, 'FTPS cache capacity');
        $profileId = trim((string) ($config['_profile_id'] ?? 'unbound'));
        if (! preg_match('/^[A-Za-z0-9-]{1,80}$/', $profileId)) throw new RuntimeException('FTPS cache namespace is invalid.');

        $paths = [];
        foreach (['ca_bundle', 'client_certificate', 'client_key'] as $field) {
            $value = trim(str_replace('\\', '/', (string) ($config[$field] ?? '')), '/');
            if ($value !== '' && (str_contains($value, '..') || ! preg_match('#^[A-Za-z0-9._/-]+$#', $value))) throw new RuntimeException("{$field} must be a safe path relative to writable/certificates.");
            $paths[$field] = $value;
        }
        $pin = trim((string) ($config['pinned_public_key'] ?? ''));
        if ($pin !== '' && ! preg_match('#^sha256//[A-Za-z0-9+/=]+$#', $pin)) throw new RuntimeException('The FTPS pinned public key must use curl sha256// format.');

        return [
            'host' => $host, 'mode' => $mode, 'port' => (int) $port,
            'remote_root' => $root, 'passive' => self::boolean($config['passive'] ?? true),
            'connect_timeout' => $connectTimeout, 'transfer_timeout' => $transferTimeout,
            'cache_ttl_seconds' => $cacheTtl, 'cache_max_bytes' => $cacheMax,
            '_profile_id' => $profileId, 'pinned_public_key' => $pin, ...$paths,
        ];
    }

    public function putFile(string $sourcePath, string $key): void
    {
        if (! is_file($sourcePath)) throw new RuntimeException('The source file for FTPS storage was not found.');
        $size = filesize($sourcePath);
        if ($size === false || $size < 0) throw new RuntimeException('The FTPS upload source could not be inspected.');
        $remote = $this->remotePath($key);
        $temporary = $remote . '.part';
        $offset = $this->transport->size($temporary) ?? 0;
        if ($offset > $size) {
            $this->transport->delete($temporary);
            $offset = 0;
        }
        if ($offset < $size) $this->transport->upload($sourcePath, $temporary, $offset);
        if ($this->transport->size($temporary) !== $size) throw new RuntimeException('FTPS upload verification failed because the remote size differs from the source.');
        try {
            $this->transport->rename($temporary, $remote);
        } catch (Throwable $error) {
            if ($this->transport->size($remote) === null) throw $error;
            $this->transport->delete($remote);
            $this->transport->rename($temporary, $remote);
        }
        if ($this->transport->size($remote) !== $size) throw new RuntimeException('FTPS publish verification failed.');
        $this->seedCache($sourcePath, $key);
    }

    public function materialize(string $key): ?string
    {
        $path = $this->cachePath($key);
        $lockPath = $path . '.lock';
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) throw new RuntimeException('The FTPS cache directory could not be created.');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('The FTPS cache lock could not be acquired.');
        }
        try {
            clearstatcache(true, $path);
            if (is_file($path) && filemtime($path) !== false && filemtime($path) >= time() - (int) $this->config['cache_ttl_seconds']) return $path;
            $remoteSize = $this->transport->size($this->remotePath($key));
            if ($remoteSize === null) {
                @unlink($path); @unlink($path . '.part');
                return null;
            }
            if (is_file($path) && filesize($path) === $remoteSize) {
                touch($path);
                return $path;
            }
            $partial = $path . '.part';
            if (is_file($path) && ! is_file($partial)) @rename($path, $partial);
            $offset = is_file($partial) ? (filesize($partial) ?: 0) : 0;
            if ($offset > $remoteSize) { @unlink($partial); $offset = 0; }
            if ($offset < $remoteSize) $this->transport->download($this->remotePath($key), $partial, $offset);
            if (! is_file($partial) || filesize($partial) !== $remoteSize) throw new RuntimeException('FTPS cache verification failed because the downloaded size differs from the remote object.');
            if (is_file($path)) @unlink($path);
            if (! rename($partial, $path)) throw new RuntimeException('The FTPS cache object could not be finalized.');
            $this->cleanupCache($path);
            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function exists(string $key): bool
    {
        return $this->transport->size($this->remotePath($key)) !== null;
    }

    public function delete(string $key): void
    {
        $remote = $this->remotePath($key);
        $cache = $this->cachePath($key);
        $directory = dirname($cache);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) throw new RuntimeException('The FTPS cache directory could not be created.');
        $lockPath = $cache . '.lock';
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('The FTPS cache lock could not be acquired for deletion.');
        }
        try {
            if ($this->transport->size($remote) !== null) $this->transport->delete($remote);
            foreach ([$cache, $cache . '.part'] as $path) if (is_file($path)) @unlink($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    public function testConnection(): array
    {
        $staging = WRITEPATH . 'storage-staging';
        if (! is_dir($staging) && ! mkdir($staging, 0775, true) && ! is_dir($staging)) return ['ok' => false, 'message' => 'The storage staging directory could not be created.'];
        $source = tempnam($staging, 'ftps-probe-');
        if ($source === false) return ['ok' => false, 'message' => 'An FTPS probe file could not be created.'];
        $key = '.cms-probe/' . bin2hex(random_bytes(8)) . '.txt';
        try {
            file_put_contents($source, 'ftps-storage-probe');
            $this->putFile($source, $key);
            if (! $this->exists($key)) throw new RuntimeException('The FTPS probe was uploaded but cannot be read back.');
            $this->delete($key);
            return ['ok' => true, 'message' => 'FTPS TLS, login, upload, size check, rename, read, and delete verified.'];
        } catch (Throwable $error) {
            try { $this->delete($key); } catch (Throwable) {}
            return ['ok' => false, 'message' => $error->getMessage()];
        } finally {
            @unlink($source);
        }
    }

    public function displayLocation(): string
    {
        return 'ftps://' . $this->config['host'] . ':' . $this->config['port'] . $this->config['remote_root'];
    }

    private function remotePath(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || ! preg_match('#^[A-Za-z0-9._/-]+$#', $key)) throw new RuntimeException('FTPS storage key contains unsupported characters.');
        self::assertRemotePath('/' . $key, 'FTPS storage key');
        return rtrim((string) $this->config['remote_root'], '/') . '/' . $key;
    }

    private function cachePath(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || ! preg_match('#^[A-Za-z0-9._/-]+$#', $key)) throw new RuntimeException('FTPS storage key contains unsupported characters.');
        self::assertRemotePath('/' . $key, 'FTPS storage key');
        $name = hash('sha256', $key) . '-' . basename($key);
        return WRITEPATH . 'storage-cache' . DIRECTORY_SEPARATOR . $this->config['_profile_id'] . DIRECTORY_SEPARATOR . $name;
    }

    private function seedCache(string $sourcePath, string $key): void
    {
        $path = $this->cachePath($key);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) throw new RuntimeException('The FTPS cache directory could not be created.');
        $temporary = $path . '.seed-' . bin2hex(random_bytes(5));
        if (! copy($sourcePath, $temporary)) throw new RuntimeException('The uploaded FTPS object could not be seeded into the CMS cache.');
        if (is_file($path)) @unlink($path);
        if (! rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('The FTPS cache seed could not be finalized.'); }
        $this->cleanupCache($path);
    }

    private function cleanupCache(string $keepPath): void
    {
        $directory = dirname($keepPath);
        $files = [];
        $total = 0;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (! is_file($path) || str_ends_with($path, '.lock') || str_ends_with($path, '.part')) continue;
            $size = filesize($path) ?: 0;
            $total += $size;
            $files[] = ['path' => $path, 'size' => $size, 'time' => filemtime($path) ?: 0];
        }
        usort($files, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);
        foreach ($files as $file) {
            if ($total <= (int) $this->config['cache_max_bytes']) break;
            if ($file['path'] === $keepPath) continue;
            if (@unlink($file['path'])) $total -= $file['size'];
        }
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

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
