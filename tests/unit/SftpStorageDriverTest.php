<?php

use App\Libraries\Storage\FtpsTransportInterface;
use App\Libraries\Storage\SftpStorageDriver;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class SftpStorageDriverTest extends CIUnitTestCase
{
    public function testSftpDriverPublishesMaterializesAndDeletesThroughPinnedProfile(): void
    {
        $namespace = 'sftp-test-' . bin2hex(random_bytes(5));
        $transport = new MemorySftpTransport();
        $driver = new SftpStorageDriver($this->config($namespace), ['username' => 'sftpuser', 'password' => 'secret'], $transport);
        $source = tempnam(WRITEPATH, 'sftp-source-');
        $this->assertNotFalse($source);
        file_put_contents($source, 'encrypted-media');
        try {
            $driver->putFile($source, 'assets/film.ldg');
            $this->assertSame('encrypted-media', $transport->objects['/sftpfiles/Testing(Hanif)/assets/film.ldg']);
            $this->assertTrue($driver->exists('assets/film.ldg'));
            $materialized = $driver->materialize('assets/film.ldg');
            $this->assertNotNull($materialized);
            $this->assertSame('encrypted-media', file_get_contents($materialized));
            $driver->delete('assets/film.ldg');
            $this->assertFalse($driver->exists('assets/film.ldg'));
            $this->assertFileDoesNotExist($materialized);
            $this->assertSame('sftp://103.165.225.221:22/sftpfiles/Testing(Hanif)', $driver->displayLocation());
        } finally {
            @unlink($source);
            $this->removeCacheDirectory($namespace);
        }
    }

    public function testSftpProbeCleansUpAndReportsHostKeyVerification(): void
    {
        $namespace = 'sftp-probe-' . bin2hex(random_bytes(5));
        $transport = new MemorySftpTransport();
        $driver = new SftpStorageDriver($this->config($namespace), ['username' => 'sftpuser', 'password' => 'secret'], $transport);
        try {
            $result = $driver->testConnection();
            $this->assertTrue($result['ok']);
            $this->assertStringContainsString('SFTP host key', $result['message']);
            $this->assertSame([], $transport->objects);
        } finally {
            $this->removeCacheDirectory($namespace);
        }
    }

    public function testSshFingerprintMatchesOpenSshSha256Format(): void
    {
        $blob = "\0\0\0\x0bssh-ed25519" . random_bytes(32);
        $hostKey = 'ssh-ed25519 ' . base64_encode($blob) . ' test-server';
        $expected = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        $this->assertSame($expected, SftpStorageDriver::fingerprintHostKey($hostKey));
    }

    public function testSftpConfigurationRequiresAHostFingerprint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fingerprint');
        SftpStorageDriver::normalizeConfig(['host' => 'example.com', 'port' => 22, 'remote_root' => '/media']);
    }

    /** @return array<string, mixed> */
    private function config(string $namespace): array
    {
        return [
            '_profile_id' => $namespace, 'host' => '103.165.225.221', 'port' => 22,
            'remote_root' => '/sftpfiles/Testing(Hanif)',
            'host_key_fingerprint' => 'SHA256:LKusNM2sN+bYO7S8hoQI4TmJwEL6Ew587WrpASArAbs',
            'connect_timeout' => 15, 'transfer_timeout' => 3600,
            'cache_ttl_seconds' => 3600, 'cache_max_bytes' => 1073741824,
        ];
    }

    private function removeCacheDirectory(string $namespace): void
    {
        $directory = WRITEPATH . 'storage-cache' . DIRECTORY_SEPARATOR . $namespace;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) @unlink($path);
        if (is_dir($directory)) @rmdir($directory);
    }
}

final class MemorySftpTransport implements FtpsTransportInterface
{
    /** @var array<string, string> */
    public array $objects = [];
    public function size(string $remotePath): ?int { return isset($this->objects[$remotePath]) ? strlen($this->objects[$remotePath]) : null; }
    public function upload(string $sourcePath, string $remotePath, int $offset): void
    {
        $source = file_get_contents($sourcePath);
        if ($source === false) throw new RuntimeException('Test source read failed.');
        $this->objects[$remotePath] = substr((string) ($this->objects[$remotePath] ?? ''), 0, $offset) . substr($source, $offset);
    }
    public function download(string $remotePath, string $destinationPath, int $offset): void
    {
        $current = $offset > 0 && is_file($destinationPath) ? (string) file_get_contents($destinationPath) : '';
        file_put_contents($destinationPath, substr($current, 0, $offset) . substr($this->objects[$remotePath], $offset));
    }
    public function rename(string $fromPath, string $toPath): void { $this->objects[$toPath] = $this->objects[$fromPath]; unset($this->objects[$fromPath]); }
    public function delete(string $remotePath): void { unset($this->objects[$remotePath]); }
}
