<?php

namespace App\Libraries;

use App\Models\MediaUploadSessionModel;
use App\Models\MediaWorkspaceSettingModel;
use RuntimeException;

final class MediaWorkspaceService
{
    public const MARKER = '.wir-cms-workspace.json';

    private const DIRECTORIES = [
        'upload_staging' => 'upload-staging',
        'storage_staging' => 'storage-staging',
        'storage_cache' => 'storage-cache',
        'php_upload_tmp' => 'php-upload-tmp',
    ];

    private MediaWorkspaceSettingModel $settings;

    public function __construct(?MediaWorkspaceSettingModel $settings = null)
    {
        $this->settings = $settings ?? new MediaWorkspaceSettingModel();
    }

    public function setting(): ?object
    {
        return $this->settings->find(1);
    }

    public function path(string $area): string
    {
        if (! isset(self::DIRECTORIES[$area])) throw new RuntimeException('Unknown media workspace directory.');
        $setting = $this->setting();
        if ($setting === null) {
            $legacy = match ($area) {
                'upload_staging' => WRITEPATH . 'upload-staging',
                'storage_staging' => WRITEPATH . 'storage-staging',
                'storage_cache' => WRITEPATH . 'storage-cache',
                'php_upload_tmp' => WRITEPATH . 'php-upload-tmp',
            };
            return rtrim($legacy, '\\/');
        }
        $root = $this->assertConfiguredWorkspaceAvailable($setting);
        return $root . DIRECTORY_SEPARATOR . self::DIRECTORIES[$area];
    }

    /** @return array<string,mixed> */
    public function configure(string $submittedPath, int $userId): array
    {
        $root = $this->prepareRoot($submittedPath);
        $existing = $this->setting();
        $markerId = $this->readMarkerId($root);
        $sameWorkspace = $existing !== null && $markerId !== null
            && hash_equals((string) $existing->workspace_id, $markerId);
        if ($this->hasActiveUploads() && ! $sameWorkspace) {
            throw new RuntimeException('Finish, cancel, or expire active media uploads before changing the CMS media workspace.');
        }
        if ($sameWorkspace) $this->assertActiveUploadFilesPresent($root);

        $workspaceId = $markerId ?? $this->uuidV4();
        if ($markerId === null) $this->writeMarker($root, $workspaceId);
        $result = $this->probe($root, $workspaceId);
        $values = [
            'workspace_id' => $workspaceId,
            'root_path' => $root,
            'last_tested_at' => gmdate('Y-m-d H:i:s'),
            'last_test_status' => 'healthy',
            'last_test_message' => $result['message'],
            'configured_by' => $userId > 0 ? $userId : null,
        ];
        if ($existing === null) {
            if (! $this->settings->insert($values)) throw new RuntimeException('Media workspace configuration could not be saved.');
        } elseif (! $this->settings->update(1, $values)) {
            throw new RuntimeException('Media workspace configuration could not be updated.');
        }
        return $this->status();
    }

    /** @return array<string,mixed> */
    public function test(): array
    {
        $setting = $this->setting();
        if ($setting === null) {
            $root = rtrim(WRITEPATH, '\\/');
            $result = $this->probeLegacy($root);
            return [...$result, 'configured' => false, 'root_path' => $root];
        }
        try {
            $root = $this->assertConfiguredWorkspaceAvailable($setting);
            $result = $this->probe($root, (string) $setting->workspace_id);
            $this->recordTest($setting, true, $result['message']);
            return [...$result, 'configured' => true, 'root_path' => $root];
        } catch (\Throwable $error) {
            $this->recordTest($setting, false, $error->getMessage());
            return ['ok' => false, 'message' => $error->getMessage(), 'configured' => true, 'root_path' => (string) $setting->root_path];
        }
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $setting = $this->setting();
        $configured = $setting !== null;
        $root = $configured ? (string) $setting->root_path : rtrim(WRITEPATH, '\\/');
        try {
            if ($configured) $root = $this->assertConfiguredWorkspaceAvailable($setting);
            $free = disk_free_space($root);
            $total = disk_total_space($root);
            return [
                'configured' => $configured, 'available' => true, 'root_path' => $root,
                'workspace_id' => $configured ? (string) $setting->workspace_id : null,
                'free_bytes' => $free === false ? null : (int) $free,
                'total_bytes' => $total === false ? null : (int) $total,
                'php_upload_tmp' => $this->phpUploadTmpStatus($root),
                'last_tested_at' => $configured ? $setting->last_tested_at : null,
                'last_test_status' => $configured ? $setting->last_test_status : null,
                'last_test_message' => $configured ? $setting->last_test_message : 'Using the CMS writable directory until a workspace is configured.',
            ];
        } catch (\Throwable $error) {
            return [
                'configured' => $configured, 'available' => false, 'root_path' => $root,
                'workspace_id' => $configured ? (string) $setting->workspace_id : null,
                'free_bytes' => null, 'total_bytes' => null,
                'php_upload_tmp' => ['path' => (string) ini_get('upload_tmp_dir'), 'recommended_path' => $root . DIRECTORY_SEPARATOR . self::DIRECTORIES['php_upload_tmp'], 'matches' => false],
                'last_tested_at' => $configured ? $setting->last_tested_at : null,
                'last_test_status' => 'failed', 'last_test_message' => $error->getMessage(),
            ];
        }
    }

    private function prepareRoot(string $submittedPath): string
    {
        $path = trim($submittedPath, " \t\n\r\0\x0B\"'");
        if ($path === '' || str_contains($path, "\0") || ! $this->isAbsolute($path)) {
            throw new RuntimeException('Enter an absolute workspace path on the CMS server.');
        }
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        if ($this->isFilesystemRoot($path)) throw new RuntimeException('A drive or filesystem root cannot be used directly as the media workspace.');
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('The media workspace directory could not be created.');
        }
        $root = realpath($path);
        if ($root === false || ! is_dir($root)) throw new RuntimeException('The media workspace directory is unavailable.');
        $root = rtrim($root, '\\/');
        $applicationRoot = rtrim((string) realpath(ROOTPATH), '\\/');
        if ($this->isSameOrChild($root, $applicationRoot)) throw new RuntimeException('The media workspace cannot be inside the CMS application directory.');
        $windows = getenv('WINDIR');
        if (is_string($windows) && $windows !== '' && $this->isSameOrChild($root, rtrim((string) realpath($windows), '\\/'))) {
            throw new RuntimeException('The Windows system directory cannot be used as the media workspace.');
        }
        return $root;
    }

    private function assertConfiguredWorkspaceAvailable(object $setting): string
    {
        $root = rtrim((string) $setting->root_path, '\\/');
        if ($root === '' || ! is_dir($root)) throw new RuntimeException('Configured media workspace is unavailable. Reconnect its disk or volume.');
        $resolved = realpath($root);
        if ($resolved === false) throw new RuntimeException('Configured media workspace cannot be resolved.');
        $resolved = rtrim($resolved, '\\/');
        $markerId = $this->readMarkerId($resolved);
        if ($markerId === null || ! hash_equals((string) $setting->workspace_id, $markerId)) {
            throw new RuntimeException('Media workspace marker does not match. Reconnect the configured disk or select the original workspace again.');
        }
        return $resolved;
    }

    /** @return array{ok:bool,message:string} */
    private function probe(string $root, string $workspaceId): array
    {
        if ($this->readMarkerId($root) !== $workspaceId) throw new RuntimeException('Media workspace identity marker could not be verified.');
        foreach (self::DIRECTORIES as $directory) {
            $path = $root . DIRECTORY_SEPARATOR . $directory;
            if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) throw new RuntimeException("Workspace directory {$directory} could not be created.");
            $this->probeDirectory($path);
        }
        return ['ok' => true, 'message' => 'Workspace identity, write, rename, read, delete, and free-space checks passed.'];
    }

    /** @return array{ok:bool,message:string} */
    private function probeLegacy(string $root): array
    {
        if (! is_dir($root)) throw new RuntimeException('The CMS writable directory is unavailable.');
        $this->probeDirectory($root);
        return ['ok' => true, 'message' => 'Legacy CMS writable workspace is available.'];
    }

    private function probeDirectory(string $directory): void
    {
        $source = $directory . DIRECTORY_SEPARATOR . '.workspace-probe-' . bin2hex(random_bytes(6));
        $target = $source . '.renamed';
        try {
            if (file_put_contents($source, 'workspace-probe', LOCK_EX) !== 15) throw new RuntimeException('Workspace write test failed.');
            if (! rename($source, $target)) throw new RuntimeException('Workspace rename test failed.');
            if (file_get_contents($target) !== 'workspace-probe') throw new RuntimeException('Workspace read test failed.');
            if (disk_free_space($directory) === false) throw new RuntimeException('Workspace free-space check failed.');
            if (! unlink($target) || is_file($target)) throw new RuntimeException('Workspace delete test failed.');
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    private function writeMarker(string $root, string $workspaceId): void
    {
        $path = $root . DIRECTORY_SEPARATOR . self::MARKER;
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $payload = json_encode(['workspace_id' => $workspaceId, 'created_at' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false || file_put_contents($temporary, $payload, LOCK_EX) === false) throw new RuntimeException('Media workspace marker could not be written.');
        if (is_file($path) && ! @unlink($path)) { @unlink($temporary); throw new RuntimeException('Existing workspace marker could not be replaced.'); }
        if (! rename($temporary, $path)) { @unlink($temporary); throw new RuntimeException('Media workspace marker could not be finalized.'); }
    }

    private function readMarkerId(string $root): ?string
    {
        $path = $root . DIRECTORY_SEPARATOR . self::MARKER;
        if (! is_file($path)) return null;
        $values = json_decode((string) file_get_contents($path), true);
        $id = is_array($values) ? (string) ($values['workspace_id'] ?? '') : '';
        return preg_match('/^[a-f0-9-]{36}$/i', $id) ? mb_strtolower($id) : null;
    }

    private function hasActiveUploads(): bool
    {
        return (new MediaUploadSessionModel())->whereIn('status', ['uploading', 'uploaded', 'queued', 'processing', 'failed'])->countAllResults() > 0;
    }

    private function assertActiveUploadFilesPresent(string $root): void
    {
        $sessions = (new MediaUploadSessionModel())->whereIn('status', ['uploading', 'uploaded', 'queued', 'processing', 'failed'])->findAll();
        $staging = $root . DIRECTORY_SEPARATOR . self::DIRECTORIES['upload_staging'];
        foreach ($sessions as $session) {
            $name = (string) $session->staging_name;
            if (! preg_match('/^[A-Za-z0-9._-]+$/', $name) || ! is_file($staging . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException('The selected workspace has the correct marker but is missing one or more active upload files.');
            }
        }
    }

    private function recordTest(object $setting, bool $ok, string $message): void
    {
        $this->settings->update($setting->id, [
            'last_tested_at' => gmdate('Y-m-d H:i:s'), 'last_test_status' => $ok ? 'healthy' : 'failed',
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
    }

    /** @return array{path:string,recommended_path:string,matches:bool} */
    private function phpUploadTmpStatus(string $root): array
    {
        $configured = trim((string) ini_get('upload_tmp_dir'));
        $effective = $configured !== '' ? $configured : sys_get_temp_dir();
        $recommended = $root . DIRECTORY_SEPARATOR . self::DIRECTORIES['php_upload_tmp'];
        return ['path' => $effective, 'recommended_path' => $recommended, 'matches' => $this->samePath($effective, $recommended)];
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 || str_starts_with($path, '\\\\');
    }

    private function isFilesystemRoot(string $path): bool
    {
        $portable = str_replace('\\', '/', $path);
        return $path === '' || $path === '/' || preg_match('#^[A-Za-z]:$#', $path) === 1 || preg_match('#^//[^/]+/[^/]+$#', $portable) === 1;
    }

    private function isSameOrChild(string $candidate, string $parent): bool
    {
        if ($parent === '') return false;
        $candidate = mb_strtolower(str_replace('\\', '/', $candidate));
        $parent = rtrim(mb_strtolower(str_replace('\\', '/', $parent)), '/');
        return $candidate === $parent || str_starts_with($candidate, $parent . '/');
    }

    private function samePath(string $left, string $right): bool
    {
        return mb_strtolower(rtrim(str_replace('\\', '/', $left), '/')) === mb_strtolower(rtrim(str_replace('\\', '/', $right), '/'));
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
