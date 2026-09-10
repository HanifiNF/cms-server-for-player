<?php

namespace App\Libraries;

use RuntimeException;

final class MediaUploadWorkerLauncher
{
    public function launch(string $sessionPublicId): void
    {
        if (! preg_match('/^[a-f0-9-]{36}$/', $sessionPublicId)) throw new RuntimeException('Upload worker session ID is invalid.');
        $php = $this->phpBinary();
        $spark = ROOTPATH . 'spark';
        if (! is_file($spark)) throw new RuntimeException('The CMS worker entry point was not found.');

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start "" /B ' . $this->windowsQuote($php) . ' ' . $this->windowsQuote($spark)
                . ' uploads:process ' . $this->windowsQuote($sessionPublicId) . ' > NUL 2>&1';
            $handle = @popen($command, 'r');
            if ($handle === false) throw new RuntimeException('The media processing worker could not be started. Configure media.workerPhpPath with the PHP CLI executable.');
            pclose($handle);
            return;
        }

        $command = escapeshellarg($php) . ' ' . escapeshellarg($spark) . ' uploads:process '
            . escapeshellarg($sessionPublicId) . ' > /dev/null 2>&1 &';
        $handle = @popen($command, 'r');
        if ($handle === false) throw new RuntimeException('The media processing worker could not be started. Configure media.workerPhpPath with the PHP CLI executable.');
        pclose($handle);
    }

    private function phpBinary(): string
    {
        $configured = trim((string) env('media.workerPhpPath', ''));
        $loadedIni = php_ini_loaded_file();
        $candidates = array_filter([
            $configured,
            is_string($loadedIni) && $loadedIni !== '' ? dirname($loadedIni) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php') : '',
            PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'),
            str_starts_with(strtolower(basename(PHP_BINARY)), 'php') ? PHP_BINARY : '',
        ]);
        foreach ($candidates as $candidate) if (is_file($candidate)) return $candidate;
        throw new RuntimeException('PHP CLI was not found for media processing. Set media.workerPhpPath in .env.');
    }

    private function windowsQuote(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
