<?php

namespace App\Libraries\Storage;

use RuntimeException;

final class LocalStorageDriver implements StorageDriverInterface
{
    private string $root;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $relativeRoot = trim(str_replace('\\', '/', (string) ($config['root'] ?? 'uploads')), '/');
        if ($relativeRoot === '' || str_contains($relativeRoot, '..') || ! preg_match('#^[A-Za-z0-9._/-]+$#', $relativeRoot)) {
            throw new RuntimeException('The local storage root is invalid.');
        }
        $this->root = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
    }

    public function putFile(string $sourcePath, string $key): void
    {
        if (! is_file($sourcePath)) throw new RuntimeException('The source file for storage was not found.');
        $destination = $this->path($key);
        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The storage directory could not be created.');
        }
        $temporary = $destination . '.upload-' . bin2hex(random_bytes(6));
        if (! copy($sourcePath, $temporary)) throw new RuntimeException('The file could not be written to local storage.');
        if (is_file($destination) && ! @unlink($destination)) {
            @unlink($temporary);
            throw new RuntimeException('The existing storage object could not be replaced.');
        }
        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            throw new RuntimeException('The storage object could not be finalized.');
        }
    }

    public function materialize(string $key): ?string
    {
        $path = $this->path($key);
        return is_file($path) ? $path : null;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && ! @unlink($path)) throw new RuntimeException('The storage object could not be deleted.');
    }

    public function testConnection(): array
    {
        try {
            if (! is_dir($this->root) && ! mkdir($this->root, 0775, true) && ! is_dir($this->root)) throw new RuntimeException('Root directory cannot be created.');
            $probe = $this->root . DIRECTORY_SEPARATOR . '.storage-probe-' . bin2hex(random_bytes(5));
            if (file_put_contents($probe, 'ok') !== 2) throw new RuntimeException('Root directory is not writable.');
            @unlink($probe);
            return ['ok' => true, 'message' => 'Connection and write access verified.'];
        } catch (\Throwable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }
    }

    public function displayLocation(): string
    {
        return $this->root;
    }

    private function path(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0") || ! preg_match('#^[A-Za-z0-9._/-]+$#', $key)) {
            throw new RuntimeException('The storage key is invalid.');
        }
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }
}
