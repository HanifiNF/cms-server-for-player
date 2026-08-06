<?php

namespace App\HTTP;

use CodeIgniter\HTTP\Response;
use Config\App;
use RuntimeException;

final class RangeFileResponse extends Response
{
    private const CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly string $filePath,
        private readonly int $start,
        private readonly int $end,
        string $filename,
        string $mimeType,
        string $etag,
        bool $partial,
    ) {
        parent::__construct(config(App::class));
        if (! is_file($filePath) || $start < 0 || $end < $start) throw new RuntimeException('Invalid ranged file response.');
        $size = filesize($filePath);
        if ($size === false || $end >= $size) throw new RuntimeException('Range exceeds the file size.');
        $safeName = basename($filename);
        $this->setStatusCode($partial ? 206 : 200);
        $this->setHeader('Accept-Ranges', 'bytes');
        $this->setHeader('ETag', $etag);
        $this->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', (int) filemtime($filePath)) . ' GMT');
        $this->setHeader('Content-Type', $mimeType !== '' ? $mimeType : 'application/octet-stream');
        $this->setHeader('Content-Length', (string) ($end - $start + 1));
        $this->setHeader('Content-Disposition', "attachment; filename*=UTF-8''" . rawurlencode($safeName));
        $this->setHeader('Content-Transfer-Encoding', 'binary');
        if ($partial) $this->setHeader('Content-Range', "bytes {$start}-{$end}/{$size}");
    }

    public function send()
    {
        if (ENVIRONMENT !== 'testing') {
            while (ob_get_level() > 0) ob_end_clean();
        }
        $this->sendHeaders();
        $this->sendCookies();
        $this->sendBody();
        return $this;
    }

    public function sendBody()
    {
        $handle = fopen($this->filePath, 'rb');
        if ($handle === false || fseek($handle, $this->start) !== 0) throw new RuntimeException('Media stream could not be opened.');
        $remaining = $this->end - $this->start + 1;
        try {
            while ($remaining > 0 && ! feof($handle)) {
                $data = fread($handle, min(self::CHUNK_BYTES, $remaining));
                if ($data === false) throw new RuntimeException('Media stream could not be read.');
                if ($data === '') break;
                echo $data;
                $remaining -= strlen($data);
                if (connection_aborted()) break;
            }
        } finally {
            fclose($handle);
        }
        return $this;
    }
}
