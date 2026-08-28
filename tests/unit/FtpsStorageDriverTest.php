<?php

use App\Libraries\Storage\FtpsStorageDriver;
use App\Libraries\Storage\FtpsTransportInterface;
use App\Libraries\StorageCredentialService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

/** @internal */
final class FtpsStorageDriverTest extends CIUnitTestCase
{
    public function testFtpsDriverResumesPublishesMaterializesAndDeletesAnObject(): void
    {
        $namespace = 'ftps-test-' . bin2hex(random_bytes(5));
        $transport = new MemoryFtpsTransport();
        $transport->objects['/company/assets/Film--12345678/film.ldg.part'] = 'encr';
        $driver = new FtpsStorageDriver([
            '_profile_id' => $namespace,
            'host' => 'ftps.company.example', 'mode' => 'explicit', 'port' => 21,
            'remote_root' => '/company', 'passive' => true,
            'connect_timeout' => 15, 'transfer_timeout' => 3600,
            'cache_ttl_seconds' => 3600, 'cache_max_bytes' => 1073741824,
        ], ['username' => 'cms-service', 'password' => 'secret'], $transport);

        $source = tempnam(WRITEPATH, 'ftps-source-');
        $this->assertNotFalse($source);
        file_put_contents($source, 'encrypted-media');
        try {
            $driver->putFile($source, 'assets/Film--12345678/film.ldg');
            $this->assertSame('encrypted-media', $transport->objects['/company/assets/Film--12345678/film.ldg']);
            $this->assertArrayNotHasKey('/company/assets/Film--12345678/film.ldg.part', $transport->objects);
            $this->assertSame([4], $transport->uploadOffsets);
            $this->assertTrue($driver->exists('assets/Film--12345678/film.ldg'));

            $seeded = $driver->materialize('assets/Film--12345678/film.ldg');
            $this->assertNotNull($seeded);
            $this->assertSame('encrypted-media', file_get_contents($seeded));
            unlink($seeded);
            $downloaded = $driver->materialize('assets/Film--12345678/film.ldg');
            $this->assertNotNull($downloaded);
            $this->assertSame('encrypted-media', file_get_contents($downloaded));
            $this->assertSame([0], $transport->downloadOffsets);

            $driver->delete('assets/Film--12345678/film.ldg');
            $this->assertFalse($driver->exists('assets/Film--12345678/film.ldg'));
            $this->assertFileDoesNotExist($downloaded);
            $this->assertTrue($driver->deleteEmptyDirectory('assets/Film--12345678'));
            $this->assertSame(['/company/assets/Film--12345678'], $transport->deletedDirectories);
        } finally {
            @unlink($source);
            $this->removeCacheDirectory($namespace);
        }
    }

    public function testFtpsConnectionProbeVerifiesTheFullObjectLifecycle(): void
    {
        $namespace = 'ftps-probe-' . bin2hex(random_bytes(5));
        $transport = new MemoryFtpsTransport();
        $driver = new FtpsStorageDriver([
            '_profile_id' => $namespace,
            'host' => 'ftps.company.example', 'mode' => 'implicit', 'port' => 990,
            'remote_root' => '/company', 'passive' => true,
            'connect_timeout' => 15, 'transfer_timeout' => 3600,
            'cache_ttl_seconds' => 3600, 'cache_max_bytes' => 1073741824,
        ], ['username' => 'cms-service', 'password' => 'secret'], $transport);
        try {
            $result = $driver->testConnection();
            $this->assertTrue($result['ok']);
            $this->assertStringContainsString('TLS', $result['message']);
            $this->assertSame([], $transport->objects);
        } finally {
            $this->removeCacheDirectory($namespace);
        }
    }

    public function testStorageCredentialsAreAuthenticatedAndTamperingIsRejected(): void
    {
        $config = new Storage();
        $config->credentialsKey = base64_encode(random_bytes(32));
        $vault = new StorageCredentialService($config);
        $payload = $vault->encrypt(['username' => 'cms-service', 'password' => 'very-secret']);
        $this->assertSame(['username' => 'cms-service', 'password' => 'very-secret'], $vault->decrypt($payload));

        $payload[strlen($payload) - 1] = $payload[strlen($payload) - 1] === 'A' ? 'B' : 'A';
        $this->expectException(RuntimeException::class);
        $vault->decrypt($payload);
    }

    private function removeCacheDirectory(string $namespace): void
    {
        $directory = WRITEPATH . 'storage-cache' . DIRECTORY_SEPARATOR . $namespace;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) @unlink($path);
        if (is_dir($directory)) @rmdir($directory);
    }
}

final class MemoryFtpsTransport implements FtpsTransportInterface
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var list<int> */
    public array $uploadOffsets = [];
    /** @var list<int> */
    public array $downloadOffsets = [];
    /** @var list<string> */
    public array $deletedDirectories = [];

    public function size(string $remotePath): ?int { return array_key_exists($remotePath, $this->objects) ? strlen($this->objects[$remotePath]) : null; }

    public function upload(string $sourcePath, string $remotePath, int $offset): void
    {
        $this->uploadOffsets[] = $offset;
        $source = file_get_contents($sourcePath);
        if ($source === false) throw new RuntimeException('Fake source read failed.');
        $this->objects[$remotePath] = substr((string) ($this->objects[$remotePath] ?? ''), 0, $offset) . substr($source, $offset);
    }

    public function download(string $remotePath, string $destinationPath, int $offset): void
    {
        $this->downloadOffsets[] = $offset;
        $current = $offset > 0 && is_file($destinationPath) ? (string) file_get_contents($destinationPath) : '';
        file_put_contents($destinationPath, substr($current, 0, $offset) . substr($this->objects[$remotePath], $offset));
    }

    public function rename(string $fromPath, string $toPath): void
    {
        if (! array_key_exists($fromPath, $this->objects)) throw new RuntimeException('Fake remote source missing.');
        $this->objects[$toPath] = $this->objects[$fromPath];
        unset($this->objects[$fromPath]);
    }

    public function delete(string $remotePath): void { unset($this->objects[$remotePath]); }

    public function deleteEmptyDirectory(string $remotePath): bool
    {
        $prefix = rtrim($remotePath, '/') . '/';
        foreach (array_keys($this->objects) as $object) if (str_starts_with($object, $prefix)) return false;
        $this->deletedDirectories[] = $remotePath;
        return true;
    }
}
