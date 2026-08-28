<?php

namespace App\Libraries\Storage;

use phpseclib3\Net\SFTP;
use RuntimeException;

final class PhpseclibSftpTransport implements FtpsTransportInterface
{
    private SFTP $sftp;

    /** @param array<string, mixed> $config @param array<string, string> $credentials */
    public function __construct(array $config, array $credentials)
    {
        $this->sftp = new SFTP((string) $config['host'], (int) $config['port'], (int) $config['connect_timeout']);
        $hostKey = $this->sftp->getServerPublicHostKey();
        if (! is_string($hostKey) || $hostKey === '') throw new RuntimeException('SFTP server did not provide an SSH host key.');
        $actual = SftpStorageDriver::fingerprintHostKey($hostKey);
        if (! hash_equals((string) $config['host_key_fingerprint'], $actual)) {
            throw new RuntimeException("SFTP host key mismatch. Expected {$config['host_key_fingerprint']}, received {$actual}.");
        }
        if (! $this->sftp->login((string) $credentials['username'], (string) $credentials['password'])) {
            throw new RuntimeException('SFTP authentication failed. Check the username and password.');
        }
        $this->sftp->setTimeout((int) $config['transfer_timeout']);
        if (! $this->sftp->is_dir((string) $config['remote_root'])) {
            throw new RuntimeException('SFTP remote root does not exist or is not accessible.');
        }
    }

    public function size(string $remotePath): ?int
    {
        $size = $this->sftp->filesize($remotePath);
        return is_int($size) && $size >= 0 ? $size : null;
    }

    public function upload(string $sourcePath, string $remotePath, int $offset): void
    {
        $directory = str_replace('\\', '/', dirname($remotePath));
        if (! $this->sftp->is_dir($directory) && ! $this->sftp->mkdir($directory, -1, true)) {
            throw new RuntimeException('SFTP could not create the remote object directory.');
        }
        if (! $this->sftp->put($remotePath, $sourcePath, SFTP::SOURCE_LOCAL_FILE, $offset, $offset)) {
            throw new RuntimeException('SFTP upload failed: ' . $this->lastError());
        }
    }

    public function download(string $remotePath, string $destinationPath, int $offset): void
    {
        $handle = fopen($destinationPath, 'c+b');
        if ($handle === false) throw new RuntimeException('SFTP cache partial file could not be opened.');
        try {
            if (fseek($handle, $offset) !== 0) throw new RuntimeException('SFTP cache partial file could not be positioned.');
            if (! $this->sftp->get($remotePath, $handle, $offset)) throw new RuntimeException('SFTP download failed: ' . $this->lastError());
        } finally {
            fclose($handle);
        }
    }

    public function rename(string $fromPath, string $toPath): void
    {
        if (! $this->sftp->rename($fromPath, $toPath)) throw new RuntimeException('SFTP atomic publish failed: ' . $this->lastError());
    }

    public function delete(string $remotePath): void
    {
        if ($this->size($remotePath) !== null && ! $this->sftp->delete($remotePath, false)) {
            throw new RuntimeException('SFTP delete failed: ' . $this->lastError());
        }
    }

    public function deleteEmptyDirectory(string $remotePath): bool
    {
        if (! $this->sftp->is_dir($remotePath)) return false;
        $items = $this->sftp->nlist($remotePath);
        if (! is_array($items)) throw new RuntimeException('SFTP directory inspection failed: ' . $this->lastError());
        $items = array_values(array_filter($items, static fn (string $item): bool => ! in_array(basename(rtrim($item, '/')), ['.', '..'], true)));
        if ($items !== []) return false;
        if (! $this->sftp->rmdir($remotePath)) throw new RuntimeException('SFTP empty directory delete failed: ' . $this->lastError());
        return true;
    }

    private function lastError(): string
    {
        $errors = $this->sftp->getSFTPErrors();
        return $errors === [] ? 'the server returned an unspecified error' : (string) end($errors);
    }
}
