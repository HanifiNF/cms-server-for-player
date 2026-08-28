<?php

namespace App\Libraries\Storage;

interface FtpsTransportInterface
{
    public function size(string $remotePath): ?int;
    public function upload(string $sourcePath, string $remotePath, int $offset): void;
    public function download(string $remotePath, string $destinationPath, int $offset): void;
    public function rename(string $fromPath, string $toPath): void;
    public function delete(string $remotePath): void;
    public function deleteEmptyDirectory(string $remotePath): bool;
}
