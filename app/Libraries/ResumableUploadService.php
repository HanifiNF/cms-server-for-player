<?php

namespace App\Libraries;

use App\Models\MediaUploadSessionModel;
use Config\Database;
use RuntimeException;
use Throwable;

final class ResumableUploadService
{
    public const CHUNK_SIZE = 67108864;
    public const TTL_SECONDS = 86400;
    public const DISK_RESERVE_BYTES = 2147483648;

    private MediaUploadSessionModel $sessions;
    private string $directory;
    private int $chunkSize;
    private int $ttlSeconds;
    private int $diskReserveBytes;

    /** @var callable(string):float|int|false */
    private $diskFreeSpace;

    /** @var resource|null */
    private $processingLock = null;

    public function __construct(
        ?MediaUploadSessionModel $sessions = null,
        ?string $directory = null,
        int $chunkSize = self::CHUNK_SIZE,
        int $ttlSeconds = self::TTL_SECONDS,
        int $diskReserveBytes = self::DISK_RESERVE_BYTES,
        ?callable $diskFreeSpace = null,
    )
    {
        if ($chunkSize <= 0 || $ttlSeconds <= 0 || $diskReserveBytes < 0) {
            throw new RuntimeException('Upload service limits are invalid.');
        }
        $this->sessions = $sessions ?? new MediaUploadSessionModel();
        $this->directory = rtrim($directory ?? (new MediaWorkspaceService())->path('upload_staging'), '\\/');
        $this->chunkSize = $chunkSize;
        $this->ttlSeconds = $ttlSeconds;
        $this->diskReserveBytes = $diskReserveBytes;
        $this->diskFreeSpace = $diskFreeSpace ?? static fn (string $path): float|int|false => disk_free_space($path);
    }

    /** @param array<string,mixed> $values */
    public function initiate(array $values): object
    {
        $this->ensureDirectory();
        $this->cleanupExpired();
        $ownerId = (int) ($values['owner_user_id'] ?? 0);
        $purpose = (string) ($values['purpose'] ?? '');
        $targetId = isset($values['target_asset_id']) ? (int) $values['target_asset_id'] : null;
        $fingerprint = strtolower(trim((string) ($values['fingerprint'] ?? '')));
        $filename = basename((string) ($values['filename'] ?? ''));
        $mimeType = trim((string) ($values['mime_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
        $size = filter_var($values['size_bytes'] ?? null, FILTER_VALIDATE_INT);
        if ($ownerId <= 0 || ! in_array($purpose, ['asset', 'revision'], true)) throw new RuntimeException('Upload session identity is invalid.');
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) throw new RuntimeException('File fingerprint is invalid.');
        if ($filename === '' || mb_strlen($filename) > 255) throw new RuntimeException('Filename is invalid.');
        if ($size === false || $size <= 0) throw new RuntimeException('File size is invalid.');

        $query = $this->sessions->where('owner_user_id', $ownerId)
            ->where('purpose', $purpose)->where('fingerprint', $fingerprint)->where('size_bytes', $size);
        $targetId === null ? $query->where('target_asset_id', null) : $query->where('target_asset_id', $targetId);
        $existing = $query->whereIn('status', ['uploading', 'uploaded', 'queued', 'processing', 'failed', 'completed'])
            ->orderBy('id', 'DESC')->first();
        if ($existing !== null) {
            if ($existing->status !== 'completed') $this->touch($existing);
            return $this->sessions->find($existing->id);
        }

        $this->assertDiskCapacity((int) $size, 0);
        $publicId = $this->uuidV4();
        $stagingName = $publicId . '.upload';
        $handle = @fopen($this->path($stagingName), 'x+b');
        if ($handle === false) throw new RuntimeException('Upload staging file could not be created.');
        fclose($handle);
        $id = $this->sessions->insert([
            'public_id' => $publicId, 'owner_user_id' => $ownerId, 'purpose' => $purpose,
            'target_asset_id' => $targetId, 'fingerprint' => $fingerprint, 'filename' => $filename,
            'mime_type' => mb_substr($mimeType, 0, 160), 'size_bytes' => (int) $size,
            'last_modified_ms' => max(0, (int) ($values['last_modified_ms'] ?? 0)),
            'chunk_size_bytes' => $this->chunkSize, 'received_bytes' => 0, 'next_chunk_index' => 0,
            'status' => 'uploading', 'staging_name' => $stagingName,
            'metadata_json' => json_encode($values['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'result_public_id' => $values['result_public_id'] ?? ($purpose === 'asset' ? $this->uuidV4() : null),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
        ], true);
        if (! is_int($id)) {
            @unlink($this->path($stagingName));
            throw new RuntimeException('Upload session could not be saved.');
        }
        return $this->sessions->find($id);
    }

    /** @param array<string,string> $posterInfo */
    public function storePoster(object $session, object $poster, array $posterInfo): object
    {
        if ($posterInfo === []) return $session;
        $source = (string) $poster->getTempName();
        if ($source === '' || ! is_file($source)) throw new RuntimeException('Poster staging file was not found.');
        $name = (string) $session->public_id . '.poster.' . (string) $posterInfo['extension'];
        $destination = $this->path($name);
        if (! @copy($source, $destination)) throw new RuntimeException('Poster could not be staged.');
        if ($session->poster_name && $session->poster_name !== $name) @unlink($this->path((string) $session->poster_name));
        $metadata = $this->metadata($session);
        $metadata['_poster'] = $posterInfo;
        $this->sessions->update($session->id, [
            'poster_name' => $name,
            'metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);
        return $this->sessions->find($session->id);
    }

    public function owned(string $publicId, int $ownerId): ?object
    {
        return $this->sessions->where('public_id', $publicId)->where('owner_user_id', $ownerId)->first();
    }

    public function find(string $publicId): ?object
    {
        return $this->sessions->where('public_id', $publicId)->first();
    }

    public function appendChunk(object $session, object $chunk, int $index, string $clientHash): object
    {
        if (! $chunk->isValid() || $chunk->hasMoved()) throw new RuntimeException('Chunk upload is invalid.');
        $total = (int) $session->size_bytes;
        $chunkSize = (int) $session->chunk_size_bytes;
        $chunkStart = $index * $chunkSize;
        $expectedBytes = min($chunkSize, $total - $chunkStart);
        $actualBytes = (int) $chunk->getSize();
        if ($index < 0 || $chunkStart < 0 || $expectedBytes <= 0 || $actualBytes !== $expectedBytes || $actualBytes > $this->chunkSize) {
            throw new RuntimeException('Chunk size does not match the expected upload range.');
        }
        $source = (string) $chunk->getTempName();
        $hash = strtolower(hash_file('sha256', $source) ?: '');
        if (! preg_match('/^[a-f0-9]{64}$/', $clientHash) || ! hash_equals(strtolower($clientHash), $hash)) {
            throw new RuntimeException('Chunk integrity verification failed.');
        }
        $expectedIndex = (int) $session->next_chunk_index;
        if ($index < $expectedIndex) {
            $this->verifyStagedChunk($session, $chunkStart, $expectedBytes, $clientHash);
            $this->touch($session);
            return $this->sessions->find($session->id);
        }
        if (! in_array((string) $session->status, ['uploading', 'failed'], true)) {
            throw new RuntimeException('This upload is not accepting chunks.');
        }
        if ($index !== $expectedIndex) throw new RuntimeException("Expected chunk {$expectedIndex}.");
        $received = (int) $session->received_bytes;
        if ($chunkStart !== $received) throw new RuntimeException('Chunk offset does not match the current upload position.');
        $this->assertDiskCapacity($total, $received);

        $destination = @fopen($this->path((string) $session->staging_name), 'c+b');
        $input = @fopen($source, 'rb');
        if ($destination === false || $input === false) {
            if (is_resource($destination)) fclose($destination);
            if (is_resource($input)) fclose($input);
            throw new RuntimeException('Chunk staging stream could not be opened.');
        }
        $nextReceived = $received + $actualBytes;
        try {
            if (! flock($destination, LOCK_EX)) throw new RuntimeException('Upload session is currently busy.');
            $lockedSession = $this->sessions->find($session->id);
            if ($lockedSession === null || (int) $lockedSession->next_chunk_index !== $expectedIndex || (int) $lockedSession->received_bytes !== $received) {
                throw new RuntimeException('Upload position changed; refresh the session status before retrying.');
            }
            $currentSize = fstat($destination)['size'] ?? 0;
            if ($currentSize < $received) throw new RuntimeException('Upload staging file is incomplete.');
            if ($currentSize > $received && ! ftruncate($destination, $received)) throw new RuntimeException('Upload staging file could not be repaired.');
            if (fseek($destination, $received) !== 0) throw new RuntimeException('Upload staging offset is invalid.');
            $written = stream_copy_to_stream($input, $destination);
            if ($written !== $actualBytes || ! fflush($destination)) throw new RuntimeException('Chunk could not be written completely.');
            if (! $this->sessions->update($session->id, [
                'received_bytes' => $nextReceived,
                'next_chunk_index' => $expectedIndex + 1,
                'status' => $nextReceived === $total ? 'uploaded' : 'uploading',
                'error_message' => null,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
            ])) throw new RuntimeException('Chunk progress could not be saved.');
            flock($destination, LOCK_UN);
        } finally {
            fclose($input);
            fclose($destination);
        }
        return $this->sessions->find($session->id);
    }

    public function beginProcessing(object $session): object
    {
        if ($session->status === 'completed') return $session;
        if ((int) $session->received_bytes !== (int) $session->size_bytes) throw new RuntimeException('Upload is not complete yet.');
        if (! is_file($this->sourcePath($session)) || filesize($this->sourcePath($session)) !== (int) $session->size_bytes) {
            throw new RuntimeException('Completed staging file is unavailable or incomplete.');
        }
        if (! $this->acquireProcessingLock($session)) {
            throw new RuntimeException('This upload is already being processed.');
        }
        $db = Database::connect();
        $db->table('media_upload_sessions')
            ->where('id', $session->id)
            ->whereIn('status', ['uploaded', 'queued', 'failed', 'processing'])
            ->update([
                'status' => 'processing', 'error_message' => null,
                'processing_stage' => 'probing', 'processing_bytes' => 0,
                'processing_total_bytes' => (int) $session->size_bytes,
                'processing_percent' => 0, 'processing_eta_seconds' => null,
                'processing_started_at' => $session->processing_started_at ?: gmdate('Y-m-d H:i:s'),
                'processing_heartbeat_at' => gmdate('Y-m-d H:i:s'),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        if ($db->affectedRows() !== 1) {
            $latest = $this->sessions->find($session->id);
            if ($latest !== null && $latest->status === 'completed') {
                $this->releaseProcessingLock($session);
                return $latest;
            }
            if ($latest !== null && $latest->status === 'processing') return $latest;
            $this->releaseProcessingLock($session);
            throw new RuntimeException('This upload is already being processed.');
        }
        return $this->sessions->find($session->id);
    }

    public function queueProcessing(object $session): object
    {
        if ($session->status === 'completed') return $session;
        if ($session->status === 'processing') {
            if (! $this->acquireProcessingLock($session)) return $session;
            $this->releaseProcessingLock($session);
        }
        if ((int) $session->received_bytes !== (int) $session->size_bytes) throw new RuntimeException('Upload is not complete yet.');
        if (! is_file($this->sourcePath($session)) || filesize($this->sourcePath($session)) !== (int) $session->size_bytes) {
            throw new RuntimeException('Completed staging file is unavailable or incomplete.');
        }
        if (! in_array((string) $session->status, ['uploaded', 'queued', 'processing', 'failed'], true)) {
            throw new RuntimeException('Upload is not ready for processing.');
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->sessions->update($session->id, [
            'status' => 'queued', 'error_message' => null,
            'processing_stage' => 'queued', 'processing_bytes' => 0,
            'processing_total_bytes' => (int) $session->size_bytes,
            'processing_percent' => 0, 'processing_eta_seconds' => null,
            'processing_started_at' => null, 'processing_heartbeat_at' => null,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
            'updated_at' => $now,
        ]);
        return $this->sessions->find($session->id);
    }

    public function updateProgress(object $session, string $stage, int $bytes, int $total, float $percent): object
    {
        $percent = max(0.0, min(100.0, $percent));
        $total = max(0, $total);
        $bytes = max(0, min($total, $bytes));
        $latest = $this->sessions->find($session->id);
        if ($latest === null || $latest->status !== 'processing') throw new RuntimeException('Upload processing session is no longer active.');
        $started = strtotime((string) $latest->processing_started_at) ?: time();
        $elapsed = max(0, time() - $started);
        $eta = $percent >= 1.0 && $percent < 100.0 ? (int) ceil($elapsed * (100.0 - $percent) / $percent) : ($percent >= 100.0 ? 0 : null);
        $now = gmdate('Y-m-d H:i:s');
        $this->sessions->update($session->id, [
            'processing_stage' => mb_substr($stage, 0, 32),
            'processing_bytes' => $bytes, 'processing_total_bytes' => $total,
            'processing_percent' => number_format($percent, 2, '.', ''),
            'processing_eta_seconds' => $eta,
            'processing_heartbeat_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
            'updated_at' => $now,
        ]);
        return $this->sessions->find($session->id);
    }

    public function complete(object $session, string $resultPublicId): object
    {
        if (! $this->sessions->update($session->id, [
            'status' => 'completed', 'result_public_id' => $resultPublicId,
            'processing_stage' => 'completed', 'processing_percent' => 100,
            'processing_eta_seconds' => 0, 'processing_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'error_message' => null, 'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
        ])) throw new RuntimeException('Completed upload state could not be saved.');
        $completed = $this->sessions->find($session->id);
        $this->releaseProcessingLock($session);
        @unlink($this->sourcePath($session));
        if ($session->poster_name) @unlink($this->path((string) $session->poster_name));
        return $completed;
    }

    public function fail(object $session, string $message): object
    {
        $this->sessions->update($session->id, [
            'status' => 'failed', 'error_message' => mb_substr($message, 0, 2000),
            'processing_stage' => 'failed', 'processing_eta_seconds' => null,
            'processing_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds),
        ]);
        $this->releaseProcessingLock($session);
        return $this->sessions->find($session->id);
    }

    public function cancel(object $session): void
    {
        $this->releaseProcessingLock($session);
        @unlink($this->sourcePath($session));
        if ($session->poster_name) @unlink($this->path((string) $session->poster_name));
        $this->sessions->delete($session->id);
    }

    public function cleanupExpired(): int
    {
        $expired = $this->sessions->where('expires_at <', gmdate('Y-m-d H:i:s'))->findAll();
        $removed = 0;
        foreach ($expired as $session) {
            try {
                if (in_array($session->status, ['queued', 'processing'], true) && ! $this->acquireProcessingLock($session)) continue;
                $this->cancel($session);
                $removed++;
            } catch (Throwable $error) {
                log_message('error', 'Expired upload cleanup failed: {message}', ['message' => $error->getMessage()]);
            }
        }
        return $removed;
    }

    /** @return array<string,mixed> */
    public function metadata(object $session): array
    {
        $value = json_decode((string) $session->metadata_json, true);
        return is_array($value) ? $value : [];
    }

    public function sourcePath(object $session): string
    {
        return $this->path((string) $session->staging_name);
    }

    public function posterPath(object $session): ?string
    {
        return $session->poster_name ? $this->path((string) $session->poster_name) : null;
    }

    /** @return array<string,mixed> */
    public function payload(object $session): array
    {
        return [
            'id' => $session->public_id, 'purpose' => $session->purpose, 'filename' => $session->filename,
            'size_bytes' => (int) $session->size_bytes, 'chunk_size_bytes' => (int) $session->chunk_size_bytes,
            'received_bytes' => (int) $session->received_bytes, 'next_chunk_index' => (int) $session->next_chunk_index,
            'status' => $session->status, 'error' => $session->error_message,
            'processing' => [
                'stage' => $session->processing_stage,
                'stage_label' => $this->stageLabel((string) $session->processing_stage),
                'processed_bytes' => (int) $session->processing_bytes,
                'total_bytes' => (int) $session->processing_total_bytes,
                'percent' => (float) $session->processing_percent,
                'eta_seconds' => $session->processing_eta_seconds === null ? null : (int) $session->processing_eta_seconds,
                'started_at' => $session->processing_started_at,
                'heartbeat_at' => $session->processing_heartbeat_at,
            ],
            'result_public_id' => $session->result_public_id, 'expires_at' => $session->expires_at,
        ];
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'queued' => 'Preparing processing',
            'probing' => 'Detecting duration',
            'hashing' => 'Verifying source',
            'encrypting' => 'Encrypting LDG',
            'storing' => 'Saving encrypted media',
            'cataloging' => 'Finalizing catalog',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => 'Waiting',
        };
    }

    private function touch(object $session): void
    {
        $this->sessions->update($session->id, ['expires_at' => gmdate('Y-m-d H:i:s', time() + $this->ttlSeconds)]);
    }

    private function assertDiskCapacity(int $total, int $received): void
    {
        $free = ($this->diskFreeSpace)($this->directory);
        $required = max(0, $total - $received) + ($total * 2) + $this->diskReserveBytes;
        if ($free !== false && (float) $free < (float) $required) {
            throw new RuntimeException('Not enough disk space to upload, encrypt, and finalize this film safely.');
        }
    }

    private function verifyStagedChunk(object $session, int $offset, int $length, string $clientHash): void
    {
        $handle = @fopen($this->sourcePath($session), 'rb');
        if ($handle === false) throw new RuntimeException('Upload staging file is unavailable.');
        try {
            if (fseek($handle, $offset) !== 0) throw new RuntimeException('Stored chunk offset is invalid.');
            $context = hash_init('sha256');
            $read = hash_update_stream($context, $handle, $length);
            $storedHash = hash_final($context);
            if ($read !== $length || ! hash_equals(strtolower($clientHash), $storedHash)) {
                throw new RuntimeException('Duplicate chunk does not match the staged data.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function acquireProcessingLock(object $session): bool
    {
        if (is_resource($this->processingLock)) return false;
        $lock = @fopen($this->sourcePath($session) . '.processing.lock', 'c+b');
        if ($lock === false) return false;
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        $this->processingLock = $lock;
        return true;
    }

    private function releaseProcessingLock(object $session): void
    {
        if (is_resource($this->processingLock)) {
            flock($this->processingLock, LOCK_UN);
            fclose($this->processingLock);
            $this->processingLock = null;
        }
        @unlink($this->sourcePath($session) . '.processing.lock');
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Upload staging directory could not be created.');
        }
    }

    private function path(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name)) throw new RuntimeException('Upload staging name is invalid.');
        return $this->directory . DIRECTORY_SEPARATOR . $name;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
