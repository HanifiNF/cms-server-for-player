<?php

namespace App\Libraries;

use RuntimeException;

final class AssetStoragePathService
{
    private const ASSET_PREFIX = 'assets/';
    private const MAX_FOLDER_TITLE_LENGTH = 72;
    private const MAX_FILE_STEM_LENGTH = 96;

    public function newMediaKey(string $title, string $publicId, int $revision = 1): string
    {
        $folder = $this->safeSegment($title, 'Film', self::MAX_FOLDER_TITLE_LENGTH)
            . '--' . $this->shortId($publicId);

        return self::ASSET_PREFIX . $folder . '/' . $this->revisionFilename($publicId, $revision);
    }

    public function revisionMediaKey(object $asset, int $revision): string
    {
        $currentKey = str_replace('\\', '/', trim((string) ($asset->storage_key ?? '')));
        $relative = $this->relativeStoragePath($currentKey);

        // Keep existing assets in their original directory. This prevents a title edit or
        // a revision from silently relocating objects that are already in production.
        if (str_contains($relative, '/')) {
            return self::ASSET_PREFIX . dirname($relative) . '/' . $this->revisionFilename((string) ($asset->public_id ?? ''), $revision);
        }

        return self::ASSET_PREFIX . $this->revisionFilename((string) ($asset->public_id ?? ''), $revision);
    }

    public function playerRelativePath(object $asset): string
    {
        $relative = $this->relativeStoragePath((string) ($asset->storage_key ?? ''));
        if (str_contains($relative, '/')) return $relative;

        // Legacy Player releases stored managed files as {asset-id}.{extension}.
        // Preserve that path so an upgrade does not download every old asset again.
        $encrypted = (string) ($asset->encryption_format ?? '') === LdgCryptoService::FORMAT;
        $extension = $encrypted ? 'ldg' : strtolower(pathinfo((string) ($asset->filename ?? ''), PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        return $this->safeSegment((string) ($asset->public_id ?? ''), 'asset', 64) . '.' . $extension;
    }

    public function assertPortableRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('Asset storage path must be relative.');
        }
        if (strlen($path) > 240) throw new RuntimeException('Asset storage path is too long.');

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Asset storage path contains an unsafe segment.');
            }
            if (strlen($segment) > 120 || preg_match('/[<>:"\\|?*\x00-\x1F]/', $segment) || preg_match('/[. ]$/', $segment)) {
                throw new RuntimeException('Asset storage path contains unsupported characters.');
            }
        }

        return implode('/', $segments);
    }

    private function relativeStoragePath(string $storageKey): string
    {
        $storageKey = str_replace('\\', '/', trim($storageKey));
        if (! str_starts_with($storageKey, self::ASSET_PREFIX)) {
            throw new RuntimeException('Asset storage key is outside the assets directory.');
        }
        return $this->assertPortableRelativePath(substr($storageKey, strlen(self::ASSET_PREFIX)));
    }

    private function revisionFilename(string $publicId, int $revision): string
    {
        $identifier = $this->safeSegment($publicId, 'asset-' . $this->shortId($publicId), self::MAX_FILE_STEM_LENGTH);
        return $identifier . '-r' . max(1, $revision) . '.ldg';
    }

    private function safeSegment(string $value, string $fallback, int $maximumLength): string
    {
        $value = preg_replace('~[<>:"/\\\\|?*\x00-\x1F]~u', ' ', trim($value)) ?? '';
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii)) $value = $ascii;
        }
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        $value = trim($value, '.-_');
        if ($value === '') $value = $fallback;
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $value)) $value = 'film-' . $value;
        if (mb_strlen($value) > $maximumLength) $value = rtrim(mb_substr($value, 0, $maximumLength), '.-_');
        return $value !== '' ? $value : $fallback;
    }

    private function shortId(string $publicId): string
    {
        $compact = strtolower((string) preg_replace('/[^a-f0-9]/i', '', $publicId));
        return substr($compact !== '' ? $compact : hash('sha256', $publicId), 0, 8);
    }
}
