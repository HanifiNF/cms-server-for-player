<?php

namespace App\Libraries\Storage;

interface StorageDriverInterface
{
    public function putFile(string $sourcePath, string $key): void;
    public function materialize(string $key): ?string;
    public function exists(string $key): bool;
    public function delete(string $key): void;
    /** @return array{ok:bool,message:string} */
    public function testConnection(): array;
    public function displayLocation(): string;
}
