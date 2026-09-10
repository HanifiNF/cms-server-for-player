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

    public function putFile(string $sourcePath, string $key, ?callable $progress = null): void
    {
        if (! is_file($sourcePath)) throw new RuntimeException('The source file for storage was not found.');
        $destination = $this->path($key);
        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('The storage directory could not be created.');
        }
        $temporary = $destination . '.upload-' . bin2hex(random_bytes(6));
        $input = fopen($sourcePath, 'rb');
        $output = fopen($temporary, 'wb');
        $total = filesize($sourcePath);
        if ($input === false || $output === false || $total === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($temporary);
            throw new RuntimeException('The file could not be opened for local storage.');
        }
        $copied = 0;
        if ($progress !== null) $progress(0, $total);
        try {
            while (! feof($input)) {
                $bytes = fread($input, 8388608);
                if ($bytes === false) throw new RuntimeException('The local storage source could not be read.');
                if ($bytes === '') break;
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($output, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('The file could not be written to local storage.');
                    $offset += $written;
                }
                $copied += strlen($bytes);
                if ($progress !== null) $progress(min($copied, $total), $total);
            }
            if (! fflush($output) || $copied !== $total) throw new RuntimeException('The file could not be written completely to local storage.');
        } catch (\Throwable $error) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            @unlink($temporary);
            throw $error;
        } finally {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
        }
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

    public function deleteEmptyDirectory(string $key): bool
    {
        $path = $this->path($key);
        if (! is_dir($path)) return false;
        $items = scandir($path);
        if ($items === false) throw new RuntimeException('The storage directory could not be inspected.');
        if (array_values(array_diff($items, ['.', '..'])) !== []) return false;
        if (! @rmdir($path)) throw new RuntimeException('The empty storage directory could not be deleted.');
        return true;
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
