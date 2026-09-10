<?php

use App\Libraries\ResumableUploadService;
use App\Models\MediaUploadSessionModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/** @internal */
final class ResumableUploadServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    private string $directory;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cms-upload-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
        $this->ownerId = $this->user('resumable-owner@example.com');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (scandir($this->directory) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') @unlink($this->directory . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testInitiateResumesOnlyForTheSameOwnerPurposeAndTarget(): void
    {
        $service = $this->service();
        $first = $service->initiate($this->values());
        $resumed = $service->initiate($this->values(['metadata' => ['title' => 'Blank form after reload']]));
        $otherOwner = $this->user('resumable-other@example.com');
        $separate = $service->initiate($this->values(['owner_user_id' => $otherOwner]));

        $this->assertSame($first->public_id, $resumed->public_id);
        $this->assertSame('Resumable Test', $service->metadata($resumed)['title']);
        $this->assertNotSame($first->public_id, $separate->public_id);
        $this->assertSame(4, (int) $first->chunk_size_bytes);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', (string) $first->result_public_id);
    }

    public function testChunksAreSequentialVerifiedAndDuplicateRetriesAreIdempotent(): void
    {
        $service = $this->service();
        $session = $service->initiate($this->values());

        try {
            $service->appendChunk($session, $this->chunk('ef'), 1, hash('sha256', 'ef'));
            $this->fail('An out-of-order chunk must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Expected chunk 0', $error->getMessage());
        }

        try {
            $service->appendChunk($session, $this->chunk('abcd'), 0, str_repeat('0', 64));
            $this->fail('A chunk with the wrong hash must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('integrity', $error->getMessage());
        }

        $session = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $this->assertSame(4, (int) $session->received_bytes);
        $duplicate = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $this->assertSame(4, (int) $duplicate->received_bytes);
        try {
            $service->appendChunk($session, $this->chunk('wxyz'), 0, hash('sha256', 'wxyz'));
            $this->fail('A duplicate chunk with different bytes must be rejected.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('does not match', $error->getMessage());
        }
        $session = $service->appendChunk($duplicate, $this->chunk('ef'), 1, hash('sha256', 'ef'));

        $this->assertSame('uploaded', $session->status);
        $this->assertSame(6, (int) $session->received_bytes);
        $this->assertSame('abcdef', file_get_contents($service->sourcePath($session)));
    }

    public function testProcessingLockCanOnlyBeAcquiredOnce(): void
    {
        $service = $this->service();
        $session = $service->initiate($this->values());
        $session = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $session = $service->appendChunk($session, $this->chunk('ef'), 1, hash('sha256', 'ef'));
        $processing = $service->beginProcessing($session);

        $this->assertSame('processing', $processing->status);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already being processed');
        $service->beginProcessing($session);
    }

    public function testInterruptedProcessingCanBeRetriedWithoutUploadingAgain(): void
    {
        $service = $this->service();
        $session = $service->initiate($this->values());
        $session = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $session = $service->appendChunk($session, $this->chunk('ef'), 1, hash('sha256', 'ef'));
        $processing = $service->beginProcessing($session);
        unset($service);

        $recovered = $this->service()->beginProcessing($processing);
        $this->assertSame('processing', $recovered->status);
        $this->assertSame(6, (int) $recovered->received_bytes);
    }

    public function testFailedFinalizationKeepsStagingAndCanCompleteIdempotently(): void
    {
        $service = $this->service();
        $session = $service->initiate($this->values());
        $session = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $session = $service->appendChunk($session, $this->chunk('ef'), 1, hash('sha256', 'ef'));
        $path = $service->sourcePath($session);
        $processing = $service->beginProcessing($session);
        $failed = $service->fail($processing, 'Simulated processor interruption.');

        $this->assertSame('failed', $failed->status);
        $this->assertFileExists($path);
        $retried = $service->beginProcessing($failed);
        $completed = $service->complete($retried, (string) $failed->result_public_id);
        $this->assertSame('completed', $completed->status);
        $this->assertFileDoesNotExist($path);
        $this->assertSame('completed', $service->beginProcessing($completed)->status);
    }

    public function testQueuedProcessingPublishesProgressAndEtaPayload(): void
    {
        $service = $this->service();
        $session = $service->initiate($this->values());
        $session = $service->appendChunk($session, $this->chunk('abcd'), 0, hash('sha256', 'abcd'));
        $session = $service->appendChunk($session, $this->chunk('ef'), 1, hash('sha256', 'ef'));
        $queued = $service->queueProcessing($session);
        $this->assertSame('queued', $queued->status);
        $processing = $service->beginProcessing($queued);
        $processing = $service->updateProgress($processing, 'hashing', 3, 6, 14.5);
        $payload = $service->payload($processing);

        $this->assertSame('processing', $payload['status']);
        $this->assertSame('hashing', $payload['processing']['stage']);
        $this->assertSame('Verifying source', $payload['processing']['stage_label']);
        $this->assertSame(3, $payload['processing']['processed_bytes']);
        $this->assertSame(6, $payload['processing']['total_bytes']);
        $this->assertSame(14.5, $payload['processing']['percent']);
        $this->assertNotNull($payload['processing']['heartbeat_at']);
    }

    public function testExpiredAndCancelledSessionsRemoveTheirStagingFiles(): void
    {
        $service = $this->service();
        $expired = $service->initiate($this->values());
        $expiredPath = $service->sourcePath($expired);
        (new MediaUploadSessionModel())->update($expired->id, ['expires_at' => gmdate('Y-m-d H:i:s', time() - 5)]);

        $this->assertSame(1, $service->cleanupExpired());
        $this->assertFileDoesNotExist($expiredPath);

        $active = $service->initiate($this->values(['fingerprint' => str_repeat('b', 64)]));
        $activePath = $service->sourcePath($active);
        $service->cancel($active);
        $this->assertFileDoesNotExist($activePath);
        $this->assertNull((new MediaUploadSessionModel())->find($active->id));
    }

    public function testUnsafeDiskCapacityRejectsTheSessionBeforeStaging(): void
    {
        $service = new ResumableUploadService(new MediaUploadSessionModel(), $this->directory, 4, 60, 10, static fn (): int => 20);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough disk space');
        $service->initiate($this->values());
    }

    private function service(): ResumableUploadService
    {
        return new ResumableUploadService(new MediaUploadSessionModel(), $this->directory, 4, 60, 0, static fn (): int => 1000000);
    }

    /** @param array<string,mixed> $overrides */
    private function values(array $overrides = []): array
    {
        return [
            'owner_user_id' => $this->ownerId,
            'purpose' => 'asset',
            'fingerprint' => str_repeat('a', 64),
            'filename' => 'resumable.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 6,
            'last_modified_ms' => 123,
            'metadata' => ['title' => 'Resumable Test'],
            ...$overrides,
        ];
    }

    private function chunk(string $contents): object
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'chunk-' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return new class($path) {
            public function __construct(private readonly string $path) {}
            public function isValid(): bool { return true; }
            public function hasMoved(): bool { return false; }
            public function getSize(): int { return filesize($this->path); }
            public function getTempName(): string { return $this->path; }
        };
    }

    private function user(string $email): int
    {
        return (new UserModel())->insert([
            'email' => $email, 'name' => 'Upload Test User',
            'password_hash' => password_hash('Password-For-Tests-2026!', PASSWORD_ARGON2ID),
            'role' => 'admin', 'status' => 'active',
        ], true);
    }
}
