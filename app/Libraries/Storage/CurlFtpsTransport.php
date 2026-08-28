<?php

namespace App\Libraries\Storage;

use CurlHandle;
use RuntimeException;

final class CurlFtpsTransport implements FtpsTransportInterface
{
    /** @param array<string, mixed> $config @param array<string, string> $credentials */
    public function __construct(private readonly array $config, private readonly array $credentials)
    {
        if (! extension_loaded('curl')) throw new RuntimeException('The PHP cURL extension is required for FTPS storage.');
    }

    public function size(string $remotePath): ?int
    {
        $handle = $this->handle($remotePath, [CURLOPT_NOBODY => true]);
        $result = curl_exec($handle);
        if ($result === false) {
            $errorNumber = curl_errno($handle);
            $message = curl_error($handle);
            curl_close($handle);
            if ($errorNumber === CURLE_REMOTE_FILE_NOT_FOUND) return null;
            throw new RuntimeException('FTPS metadata request failed: ' . $message);
        }
        $size = curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
        curl_close($handle);
        return is_int($size) && $size >= 0 ? $size : null;
    }

    public function upload(string $sourcePath, string $remotePath, int $offset): void
    {
        $totalSize = filesize($sourcePath);
        if ($totalSize === false || $offset < 0 || $offset > $totalSize) throw new RuntimeException('The FTPS upload source or resume offset is invalid.');
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false || ($offset > 0 && fseek($stream, $offset) !== 0)) {
            if (is_resource($stream)) fclose($stream);
            throw new RuntimeException('The FTPS upload source could not be opened.');
        }
        $handle = $this->handle($remotePath, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE_LARGE => $totalSize - $offset,
            CURLOPT_RESUME_FROM => $offset,
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
        ]);
        try {
            $this->execute($handle, 'FTPS upload failed');
        } finally {
            curl_close($handle);
            fclose($stream);
        }
    }

    public function download(string $remotePath, string $destinationPath, int $offset): void
    {
        $directory = dirname($destinationPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) throw new RuntimeException('The FTPS cache directory could not be created.');
        $stream = fopen($destinationPath, $offset > 0 ? 'c+b' : 'wb');
        if ($stream === false || ($offset > 0 && fseek($stream, $offset) !== 0)) {
            if (is_resource($stream)) fclose($stream);
            throw new RuntimeException('The FTPS cache file could not be opened.');
        }
        if ($offset === 0 && ! ftruncate($stream, 0)) {
            fclose($stream);
            throw new RuntimeException('The FTPS cache file could not be reset.');
        }
        $handle = $this->handle($remotePath, [CURLOPT_FILE => $stream, CURLOPT_RESUME_FROM => $offset]);
        try {
            $this->execute($handle, 'FTPS download failed');
            fflush($stream);
        } finally {
            curl_close($handle);
            fclose($stream);
        }
    }

    public function rename(string $fromPath, string $toPath): void
    {
        $this->quote(['RNFR ' . $fromPath, 'RNTO ' . $toPath], 'FTPS atomic rename failed');
    }

    public function delete(string $remotePath): void
    {
        $this->quote(['DELE ' . $remotePath], 'FTPS delete failed');
    }

    public function deleteEmptyDirectory(string $remotePath): bool
    {
        $directory = rtrim($remotePath, '/') . '/';
        $handle = $this->handle($directory, [CURLOPT_DIRLISTONLY => true, CURLOPT_RETURNTRANSFER => true]);
        try {
            $listing = curl_exec($handle);
            if ($listing === false) {
                if (curl_errno($handle) === CURLE_REMOTE_FILE_NOT_FOUND) return false;
                throw new RuntimeException('FTPS directory inspection failed: ' . curl_error($handle));
            }
        } finally {
            curl_close($handle);
        }
        $items = preg_split('/\r\n|\r|\n/', trim((string) $listing)) ?: [];
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== '' && ! in_array(basename(rtrim($item, '/')), ['.', '..'], true)));
        if ($items !== []) return false;
        $this->quote(['RMD ' . rtrim($remotePath, '/')], 'FTPS empty directory delete failed');
        return true;
    }

    /** @param list<string> $commands */
    private function quote(array $commands, string $context): void
    {
        $handle = $this->handle('/', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => $commands]);
        try { $this->execute($handle, $context); }
        finally { curl_close($handle); }
    }

    /** @param array<int, mixed> $options */
    private function handle(string $remotePath, array $options): CurlHandle
    {
        $handle = curl_init($this->url($remotePath));
        if (! $handle instanceof CurlHandle) throw new RuntimeException('The FTPS request could not be initialized.');
        $username = (string) ($this->credentials['username'] ?? '');
        $password = (string) ($this->credentials['password'] ?? '');
        $common = [
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_PORT => (int) $this->config['port'],
            CURLOPT_CONNECTTIMEOUT => (int) $this->config['connect_timeout'],
            CURLOPT_TIMEOUT => (int) $this->config['transfer_timeout'],
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => min(120, (int) $this->config['transfer_timeout']),
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_USE_EPSV => (bool) $this->config['passive'],
            CURLOPT_RETURNTRANSFER => false,
        ];
        if (! (bool) $this->config['passive']) $common[CURLOPT_FTPPORT] = '-';
        if ((string) $this->config['ca_bundle'] !== '') $common[CURLOPT_CAINFO] = $this->certificatePath((string) $this->config['ca_bundle']);
        if ((string) $this->config['client_certificate'] !== '') $common[CURLOPT_SSLCERT] = $this->certificatePath((string) $this->config['client_certificate']);
        if ((string) $this->config['client_key'] !== '') $common[CURLOPT_SSLKEY] = $this->certificatePath((string) $this->config['client_key']);
        if ((string) ($this->credentials['client_key_password'] ?? '') !== '') $common[CURLOPT_KEYPASSWD] = $this->credentials['client_key_password'];
        if ((string) $this->config['pinned_public_key'] !== '') $common[CURLOPT_PINNEDPUBLICKEY] = (string) $this->config['pinned_public_key'];
        if (! curl_setopt_array($handle, $options + $common)) {
            curl_close($handle);
            throw new RuntimeException('The FTPS request options could not be configured.');
        }
        return $handle;
    }

    private function execute(CurlHandle $handle, string $context): void
    {
        if (curl_exec($handle) === false) throw new RuntimeException($context . ': ' . curl_error($handle));
    }

    private function url(string $remotePath): string
    {
        $scheme = $this->config['mode'] === 'implicit' ? 'ftps' : 'ftp';
        $path = implode('/', array_map('rawurlencode', array_values(array_filter(explode('/', str_replace('\\', '/', $remotePath)), static fn (string $part): bool => $part !== ''))));
        return $scheme . '://' . $this->config['host'] . ':' . $this->config['port'] . '/' . $path;
    }

    private function certificatePath(string $relativePath): string
    {
        $root = realpath(WRITEPATH . 'certificates');
        $path = realpath(WRITEPATH . 'certificates' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($root === false || $path === false || ! str_starts_with($path, $root . DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('Configured FTPS certificate file was not found in writable/certificates.');
        }
        return $path;
    }
}
