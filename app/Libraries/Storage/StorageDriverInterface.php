<?php

namespace App\Libraries\Storage;

interface StorageDriverInterface
{
    /** @param callable(int,int):void|null $progress */
    public function putFile(string $sourcePath, string $key, ?callable $progress = null): void;
    public function materialize(string $key): ?string;
    public function exists(string $key): bool;
    public function delete(string $key): void;
    public function deleteEmptyDirectory(string $key): bool;
    /** @return array{ok:bool,message:string} */
    public function testConnection(): array;
    public function displayLocation(): string;
}
